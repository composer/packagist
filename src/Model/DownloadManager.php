<?php declare(strict_types=1);

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

use App\Entity\Download;
use App\Entity\Package;
use App\Entity\Version;
use App\Util\DoctrineTrait;
use Composer\Pcre\Preg;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;

/**
 * Manages the download counts for packages.
 */
class DownloadManager
{
    use DoctrineTrait;

    public function __construct(private Client $redis, private ManagerRegistry $doctrine)
    {
    }

    /**
     * Gets the total, monthly, and daily download counts for an entire package or optionally a version.
     *
     * @return array{total: int, monthly: int, daily: float, views?: int}
     */
    public function getDownloads(Package|int $package, Version|int|null $version = null, bool $incrViews = false): array
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

        $record = $this->getEM()->getRepository(Download::class)->findOneBy(['id' => $id, 'type' => $type]);
        $dlData = $record ? $record->getData() : [];

        $keyBase .= ':';
        $date = new \DateTime();
        $todayDate = (int) $date->format('Ymd');
        $yesterdayDate = date('Ymd', ((int) $date->format('U')) - 86400);

        // fetch today, yesterday and the latest total from redis
        $redisData = $this->redis->mget([$keyBase.$todayDate, $keyBase.$yesterdayDate, 'dl:'.$package]);
        $monthly = 0;
        for ($i = 0; $i < 30; $i++) {
            // current day and previous day might not be in db yet or incomplete, so we take the data from redis if there is still data there
            if ($i <= 1) {
                $monthly += $redisData[$i] ?? $dlData[(int) $date->format('Ymd')] ?? 0;
            } else {
                $monthly += $dlData[(int) $date->format('Ymd')] ?? 0;
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
     */
    public function getTotalDownloads(Package|int $package): int
    {
        if ($package instanceof Package) {
            $package = $package->getId();
        }

        return (int) $this->redis->get('dl:'.$package) ?: 0;
    }

    /**
     * Gets total download counts for multiple package IDs.
     *
     * @param array<int> $packageIds
     *
     * @return array<int, int> a map of package ID to download count
     */
    public function getPackagesDownloads(array $packageIds): array
    {
        $keys = [];

        foreach ($packageIds as $id) {
            if (ctype_digit((string) $id)) {
                $keys[$id] = 'dl:'.$id;
            }
        }

        if (!$keys) {
            return [];
        }

        $res = array_map('intval', $this->redis->mget(array_values($keys)));

        return array_combine(array_keys($keys), $res);
    }

    /**
     * Tracks downloads by updating the relevant keys.
     *
     * @param list<array{id: int, vid: int, minor: string}> $jobs Each job contains id (package id), vid (version id) and ip keys
     */
    public function addDownloads(array $jobs, string $ip, string $phpMinor, string $phpMinorPlatform): void
    {
        if (!$jobs) {
            return;
        }

        $now = time();
        $throttleExpiry = strtotime('tomorrow 12:00:00', $now - 86400 / 2) * 1000;
        $throttleDay = date('Ymd', $throttleExpiry);
        $day = date('Ymd', $now);
        $month = date('Ym', $now);

        // init keys, see numInitKeys in lua script
        $args = [
            'downloads',
            'downloads:'.$day,
            'downloads:'.$month,
            'php:'.$phpMinor.':',
            'phpplatform:'.$phpMinorPlatform.':',
        ];

        foreach ($jobs as $job) {
            $package = $job['id'];
            $version = $job['vid'];
            $minorVersion = str_replace(':', '', $job['minor']);

            // job keys, see numKeysPerJob in lua script
            // throttle key
            $args[] = 'throttle:'.$package.':'.$throttleDay;
            // stats keys
            $args[] = 'dl:'.$package;
            $args[] = 'dl:'.$package.':'.$day;
            $args[] = 'dl:'.$package.'-'.$version.':'.$day;
            $args[] = 'phpplatform:'.$package.'-'.$minorVersion.':'.$phpMinorPlatform.':'.$day;
        }

        // actual args, see ACTUAL ARGS in DownloadsIncr::getKeysCount
        $args[] = $ip;
        $args[] = $day;
        $args[] = $month;
        $args[] = $throttleExpiry;

        /* @phpstan-ignore-next-line method.notFound */
        $this->redis->downloadsIncr(...$args);
    }

    /**
     * @param string[] $keys
     */
    public function transferDownloadsToDb(int $packageId, array $keys, \DateTimeImmutable $now): void
    {
        $package = $this->getEM()->getRepository(Package::class)->find($packageId);
        // package was deleted in the meantime, abort
        if (!$package) {
            $this->redis->del($keys);

            return;
        }

        $versionsWithDownloads = [];
        foreach ($keys as $key) {
            if (Preg::isMatch('{^dl:'.$packageId.'-(\d+):\d+$}', $key, $match)) {
                $versionsWithDownloads[(int) $match[1]] = true;
            }
        }

        $versionIds = $this->getEM()->getConnection()->fetchFirstColumn(
            'SELECT id FROM package_version WHERE id IN (:ids)',
            ['ids' => array_keys($versionsWithDownloads)],
            ['ids' => ArrayParameterType::INTEGER]
        );
        $versionIds = array_map('intval', $versionIds);
        unset($versionsWithDownloads);

        sort($keys);

        $values = $this->redis->mget($keys);

        $buffer = [];
        $lastPrefix = null;

        foreach ($keys as $index => $key) {
            $prefix = Preg::replace('{:\d+$}', ':', $key);

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

        $this->getEM()->flush();

        $this->redis->del($keys);
    }

    /**
     * @param array<string, int> $keys            array of keys => dl count
     * @param list<int>          $validVersionIds
     */
    private function createDbRecordsForKeys(Package $package, array $keys, array $validVersionIds, \DateTimeImmutable $now): void
    {
        reset($keys);
        [$id, $type] = $this->getKeyInfo((string) key($keys));

        // skip if the version was deleted in the meantime
        if ($type === Download::TYPE_VERSION && !in_array($id, $validVersionIds, true)) {
            return;
        }

        $record = $this->getEM()->getRepository(Download::class)->findOneBy(['id' => $id, 'type' => $type]);
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
            if (!Preg::isMatch('{:(\d+)$}', $key, $match)) {
                throw new \LogicException('Malformed key does not end with a date stamp in form YYYYMMDD');
            }
            /** @var numeric-string $date */
            $date = $match[1];
            if ($val > 0) {
                $record->setDataPoint($date, $val);
            }
        }

        // only store records for packages or for versions that have had downloads to avoid storing empty records
        if (!$isNewRecord || $type === Download::TYPE_PACKAGE || count($record->getData()) > 0) {
            $this->getEM()->persist($record);
        }

        $record->computeSum();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function getKeyInfo(string $key): array
    {
        if (Preg::isMatch('{^dl:(\d+):}', $key, $match)) {
            return [(int) $match[1], Download::TYPE_PACKAGE];
        }

        if (Preg::isMatch('{^dl:\d+-(\d+):}', $key, $match)) {
            return [(int) $match[1], Download::TYPE_VERSION];
        }

        throw new \LogicException('Invalid key given: '.$key);
    }
}
