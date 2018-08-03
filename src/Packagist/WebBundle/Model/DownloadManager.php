<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packagist\WebBundle\Model;

use Doctrine\Common\Persistence\ManagerRegistry;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Entity\Download;
use Predis\Client;
use DateTimeImmutable;

/**
 * Manages the download counts for packages.
 */
class DownloadManager
{
    protected $redis;
    protected $doctrine;
    protected $redisCommandLoaded = false;

    public function __construct(Client $redis, ManagerRegistry $doctrine)
    {
        $this->redis = $redis;
        $this->doctrine = $doctrine;
    }

    /**
     * Gets the total, monthly, and daily download counts for an entire package or optionally a version.
     *
     * @param \Packagist\WebBundle\Entity\Package|int      $package
     * @param \Packagist\WebBundle\Entity\Version|int|null $version
     * @return array
     */
    public function getDownloads($package, $version = null)
    {
        if ($package instanceof Package) {
            $package = $package->getId();
        }

        if ($version instanceof Version) {
            $version = $version->getId();
        }

        $type = Download::TYPE_PACKAGE;
        $id = $package;
        $keyBase = 'dl:'.$package;

        if ($version !== null) {
            $id = $version;
            $type = Download::TYPE_PACKAGE;
            $keyBase .= '-'.$version;
        }

        $record = $this->doctrine->getRepository(Download::class)->findOneBy(['id' => $id, 'type' => $type]);
        $dlData = $record ? $record->data : [];

        $keyBase .= ':';
        $date = new \DateTime();
        $todayDate = $date->format('Ymd');
        $yesterdayDate = date('Ymd', $date->format('U') - 86400);

        // fetch today, yesterday and the latest total from redis
        $redisData = $this->redis->mget([$keyBase.$todayDate, $keyBase.$yesterdayDate, 'dl:'.$package]);
        $monthly = 0;
        for ($i = 0; $i < 30; $i++) {
            // current day and previous day might not be in db yet or incomplete, so we take the data from redis if there is still data there
            if ($i <= 1) {
                $monthly += $redisData[$i] ?? $dlData[$date->format('Ymd')] ?? 0;
            } else {
                $monthly += $dlData[$date->format('Ymd')] ?? 0;
            }
            $date->modify('-1 day');
        }

        $total = (int) $redisData[2];

        // how much of yesterday to add to today to make it a whole day (sort of..)
        $dayRatio = (2400 - (int) date('Hi')) / 2400;

        return [
            'total' => $total,
            'monthly' => $monthly,
            'daily' => round(($redisData[0] ?? $dlData[$todayDate] ?? 0) + (($redisData[1] ?? $dlData[$yesterdayDate] ?? 0) * $dayRatio)),
        ];
    }

    /**
     * Gets the total download count for a package.
     *
     * @param \Packagist\WebBundle\Entity\Package|int $package
     * @return int
     */
    public function getTotalDownloads($package)
    {
        if ($package instanceof Package) {
            $package = $package->getId();
        }

        return (int) $this->redis->get('dl:' . $package) ?: 0;
    }

    /**
     * Gets total download counts for multiple package IDs.
     *
     * @param array $packageIds
     * @return array a map of package ID to download count
     */
    public function getPackagesDownloads(array $packageIds)
    {
        $keys = array();

        foreach ($packageIds as $id) {
            if (ctype_digit((string) $id)) {
                $keys[$id] = 'dl:'.$id;
            }
        }

        if (!$keys) {
            return array();
        }

        $res = array_map('intval', $this->redis->mget(array_values($keys)));
        return array_combine(array_keys($keys), $res);
    }

    /**
     * Tracks downloads by updating the relevant keys.
     *
     * @param array[] an array of arrays containing id (package id), vid (version id) and ip keys
     */
    public function addDownloads(array $jobs)
    {
        $day = date('Ymd');
        $month = date('Ym');

        if (!$this->redisCommandLoaded) {
            $this->redis->getProfile()->defineCommand('downloadsIncr', 'Packagist\Redis\DownloadsIncr');
            $this->redisCommandLoaded = true;
        }

        $args = ['downloads', 'downloads:'.$day, 'downloads:'.$month];

        foreach ($jobs as $job) {
            $package = $job['id'];
            $version = $job['vid'];

            // throttle key
            $args[] = 'throttle:'.$package.':'.$day;
            // stats keys
            $args[] = 'dl:'.$package;
            $args[] = 'dl:'.$package.':'.$day;
            $args[] = 'dl:'.$package.'-'.$version.':'.$day;
        }

        $args[] = $job['ip'];

        $this->redis->downloadsIncr(...$args);
    }

    public function transferDownloadsToDb(Package $package, DateTimeImmutable $lastUpdated)
    {
        // might be a large dataset coming through here especially on first run due to historical data
        ini_set('memory_limit', '1G');

        $packageId = $package->getId();
        $rows = $this->doctrine->getManager()->getConnection()->fetchAll('SELECT id FROM package_version WHERE package_id = :id', ['id' => $packageId]);
        $versionIds = [];
        foreach ($rows as $row) {
            $versionIds[] = $row['id'];
        }

        $now = new DateTimeImmutable();
        $keys = [];
        $firstIteration = true;
        while ($lastUpdated < $now) {
            // TODO delete once the redis db has been purged
            if ($firstIteration || $lastUpdated->format('d') === '01') {
                $firstIteration = false;
                // dl:$package:Ym
                $keys[] = 'dl:'.$packageId.':'.$lastUpdated->format('Ym');
                foreach ($versionIds as $id) {
                    // dl:$package-$version and dl:$package-$version:Ym
                    $keys[] = 'dl:'.$packageId.'-'.$id;
                    $keys[] = 'dl:'.$packageId.'-'.$id.':'.$lastUpdated->format('Ym');
                }
            }

            // dl:$package:Ymd
            $keys[] = 'dl:'.$packageId.':'.$lastUpdated->format('Ymd');
            foreach ($versionIds as $id) {
                // dl:$package-$version:Ymd
                $keys[] = 'dl:'.$packageId.'-'.$id.':'.$lastUpdated->format('Ymd');
            }

            $lastUpdated = $lastUpdated->modify('+1day');
        }

        sort($keys);

        $buffer = [];
        $toDelete = [];
        $lastPrefix = '';

        foreach ($keys as $key) {
            // ignore IP keys temporarily until they all switch to throttle:* prefix
            if (preg_match('{^dl:\d+:(\d+\.|[0-9a-f]+:[0-9a-f]+:)}', $key)) {
                continue;
            }

            // delete version totals when we find one
            if (preg_match('{^dl:\d+-\d+$}', $key)) {
                $toDelete[] = $key;
                continue;
            }

            $prefix = preg_replace('{:\d+$}', ':', $key);

            if ($lastPrefix && $prefix !== $lastPrefix && $buffer) {
                $toDelete = $this->createDbRecordsForKeys($package, $buffer, $toDelete, $now);
                $buffer = [];
            }

            $buffer[] = $key;
            $lastPrefix = $prefix;
        }

        if ($buffer) {
            $toDelete = $this->createDbRecordsForKeys($package, $buffer, $toDelete, $now);
        }

        $this->doctrine->getManager()->flush();

        while ($toDelete) {
            $batch = array_splice($toDelete, 0, 1000);
            $this->redis->del($batch);
        }
    }

    private function createDbRecordsForKeys(Package $package, array $keys, array $toDelete, DateTimeImmutable $now): array
    {
        list($id, $type) = $this->getKeyInfo($keys[0]);
        $record = $this->doctrine->getRepository(Download::class)->findOneBy(['id' => $id, 'type' => $type]);
        $isNewRecord = false;
        if (!$record) {
            $record = new Download();
            $record->setId($id);
            $record->setType($type);
            $record->setPackage($package);
            $isNewRecord = true;
        }

        $today = date('Ymd');
        $record->setLastUpdated($now);

        $values = $this->redis->mget($keys);
        foreach ($keys as $index => $key) {
            $date = preg_replace('{^.*?:(\d+)$}', '$1', $key);

            // monthly data point, discard
            if (strlen($date) === 6) {
                $toDelete[] = $key;
                continue;
            }

            $val = (int) $values[$index];
            if ($val) {
                $record->setDataPoint($date, $val);
            }
            // today's value is not deleted yet as it might not be complete and we want to update it when its complete
            if ($date !== $today) {
                $toDelete[] = $key;
            }
        }

        // only store records for packages or for versions that have had downloads to avoid storing empty records
        if ($isNewRecord && ($type === Download::TYPE_PACKAGE || count($record->getData()) > 0)) {
            $this->doctrine->getManager()->persist($record);
        }

        $record->computeSum();

        return $toDelete;
    }

    private function getKeyInfo(string $key): array
    {
        if (preg_match('{^dl:(\d+):}', $key, $match)) {
            return [(int) $match[1], Download::TYPE_PACKAGE];
        }

        if (preg_match('{^dl:\d+-(\d+):}', $key, $match)) {
            return [(int) $match[1], Download::TYPE_VERSION];
        }

        throw new \LogicException('Invalid key given: '.$key);
    }
}
