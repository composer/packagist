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

namespace App\Model;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\DBAL\Connection;
use App\Entity\Package;
use App\Entity\Version;
use App\Entity\Download;
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
     * @param \App\Entity\Package|int      $package
     * @param \App\Entity\Version|int|null $version
     * @return array
     */
    public function getDownloads($package, $version = null, bool $incrViews = false)
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
            $type = Download::TYPE_VERSION;
            $keyBase .= '-'.$version;
        }

        $record = $this->doctrine->getRepository(Download::class)->findOneBy(['id' => $id, 'type' => $type]);
        $dlData = $record ? $record->getData() : [];

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
        if ($version) {
            $total = $record ? $record->getTotal() : 0;
        }

        // how much of yesterday to add to today to make it a whole day (sort of..)
        $dayRatio = (2400 - (int) date('Hi')) / 2400;

        $result = [
            'total' => $total,
            'monthly' => $monthly,
            'daily' => round(($redisData[0] ?? $dlData[$todayDate] ?? 0) + (($redisData[1] ?? $dlData[$yesterdayDate] ?? 0) * $dayRatio)),
        ];

        if ($incrViews) {
            $result['views'] = $this->redis->incr('views:'.$package);
        }

        return $result;
    }

    /**
     * Gets the total download count for a package.
     *
     * @param \App\Entity\Package|int $package
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
            $this->redis->getProfile()->defineCommand('downloadsIncr', 'App\Redis\DownloadsIncr');
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

    public function transferDownloadsToDb(int $packageId, array $keys, DateTimeImmutable $now)
    {
        $package = $this->doctrine->getRepository(Package::class)->findOneById($packageId);
        // package was deleted in the meantime, abort
        if (!$package) {
            $this->redis->del($keys);
            return;
        }

        $versionsWithDownloads = [];
        foreach ($keys as $key) {
            if (preg_match('{^dl:'.$packageId.'-(\d+):\d+$}', $key, $match)) {
                $versionsWithDownloads[(int) $match[1]] = true;
            }
        }

        $rows = $this->doctrine->getManager()->getConnection()->fetchAll(
            'SELECT id FROM package_version WHERE id IN (:ids)',
            ['ids' => array_keys($versionsWithDownloads)],
            ['ids' => Connection::PARAM_INT_ARRAY]
        );
        $versionIds = [];
        foreach ($rows as $row) {
            $versionIds[] = (int) $row['id'];
        }
        unset($versionsWithDownloads, $rows, $row);

        sort($keys);

        $values = $this->redis->mget($keys);

        $buffer = [];
        $lastPrefix = null;

        foreach ($keys as $index => $key) {
            $prefix = preg_replace('{:\d+$}', ':', $key);

            if ($lastPrefix && $prefix !== $lastPrefix && $buffer) {
                $this->createDbRecordsForKeys($package, $buffer, $versionIds, $now);
                $buffer = [];
            }

            $buffer[$key] = (int) $values[$index];
            $lastPrefix = $prefix;
        }

        if ($buffer) {
            $this->createDbRecordsForKeys($package, $buffer, $versionIds, $now);
        }

        $this->doctrine->getManager()->flush();

        $this->redis->del($keys);
    }

    private function createDbRecordsForKeys(Package $package, array $keys, array $validVersionIds, DateTimeImmutable $now)
    {
        reset($keys);
        list($id, $type) = $this->getKeyInfo(key($keys));

        // skip if the version was deleted in the meantime
        if ($type === Download::TYPE_VERSION && !in_array($id, $validVersionIds, true)) {
            return;
        }

        $record = $this->doctrine->getRepository(Download::class)->findOneBy(['id' => $id, 'type' => $type]);
        $isNewRecord = false;
        if (!$record) {
            $record = new Download();
            $record->setId($id);
            $record->setType($type);
            $record->setPackage($package);
            $isNewRecord = true;
        }

        $record->setLastUpdated($now);

        foreach ($keys as $key => $val) {
            $date = preg_replace('{^.*?:(\d+)$}', '$1', $key);
            if ($val) {
                $record->setDataPoint($date, $val);
            }
        }

        // only store records for packages or for versions that have had downloads to avoid storing empty records
        if ($isNewRecord && ($type === Download::TYPE_PACKAGE || count($record->getData()) > 0)) {
            $this->doctrine->getManager()->persist($record);
        }

        $record->computeSum();
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
