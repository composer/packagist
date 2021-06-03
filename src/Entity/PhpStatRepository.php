<?php declare(strict_types=1);

namespace App\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;
use DateTimeImmutable;

class PhpStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private Client $redis)
    {
        parent::__construct($registry, PhpStat::class);
    }

    /**
     * @param Package $package
     * @return list<array{version: string, depth: PhpStat::DEPTH_*}>
     */
    public function getStatVersions(Package $package): array
    {
        $query = $this->createQueryBuilder('s')
            ->select('s.version, s.depth')
            ->where('s.package = :package AND s.type = :type')
            ->getQuery();

        $query->setParameters(
            ['package' => $package, 'type' => PhpStat::TYPE_PHP]
        );

        return $query->getArrayResult();
    }

    /**
     * @param  'months'|'days'     $period
     * @param  'php'|'phpplatform' $type
     *
     * @return array{labels: string[], values: array<string, int[]>}
     */
    public function getGlobalChartData(array $versions, string $period, string $type): array
    {
        foreach ($versions as $version) {
            $series[$version] = $this->redis->hgetall($type.':'.$version.':'.$period);
        }

        // filter out series which have only 0 values
        $datePoints = [];
        foreach ($series as $seriesName => $data) {
            $empty = true;
            foreach ($data as $date => $value) {
                $datePoints[$date] = true;
                if ($value !== 0) {
                    $empty = false;
                }
            }
            if ($empty) {
                unset($series[$seriesName]);
            }
        }

        ksort($datePoints);
        $datePoints = array_map('strval', array_keys($datePoints));

        foreach ($series as $seriesName => $data) {
            foreach ($datePoints as $date) {
                $series[$seriesName][$date] = (int) ($data[$date] ?? 0);
            }
            $series[$seriesName] = array_values($series[$seriesName]);
        }

        if ($period === 'months') {
            $datePoints = array_map(fn ($point) => substr($point, 0, 4).'-'.substr($point, 4), $datePoints);
        } else {
            $datePoints = array_map(fn ($point) => substr($point, 0, 4).'-'.substr($point, 4, 2).'-'.substr($point, 6), $datePoints);
        }

        uksort($series, function ($a, $b) {
            if ($a === 'hhvm') {
                return 1;
            }
            if ($b === 'hhvm') {
                return -1;
            }
            return $b <=> $a;
        });

        return [
            'labels' => $datePoints,
            'values' => $series,
        ];
    }

    public function deletePackageStats(Package $package)
    {
        $conn = $this->getEntityManager()->getConnection();

        $conn->executeStatement('DELETE FROM php_stat WHERE package_id = :id', ['id' => $package->getId()]);
    }

    public function transferStatsToDb(int $packageId, array $keys, DateTimeImmutable $now, DateTimeImmutable $updateDateForMajor): void
    {
        $package = $this->getEntityManager()->getRepository(Package::class)->find($packageId);
        // package was deleted in the meantime, abort
        if (!$package) {
            $this->redis->del($keys);
            return;
        }

        sort($keys);

        $values = $this->redis->mget($keys);

        $buffer = [];
        $lastPrefix = null;
        $addedData = false;

        foreach ($keys as $index => $key) {
            // strip php minor version and date from the key to get the primary prefix (i.e. type:package-version:*)
            $prefix = preg_replace('{:\d+\.\d+:\d+$}', ':', $key);

            if ($lastPrefix && $prefix !== $lastPrefix && $buffer) {
                $addedData = $this->createDbRecordsForKeys($package, $buffer, $now) || $addedData;
                $this->redis->del(array_keys($buffer));
                $buffer = [];
            }

            $buffer[$key] = (int) $values[$index];
            $lastPrefix = $prefix;
        }

        if ($buffer) {
            $addedData = $this->createDbRecordsForKeys($package, $buffer, $now) || $addedData;
            $this->redis->del(array_keys($buffer));
        }

        if ($addedData) {
            $this->createOrUpdateMainRecord($package, PhpStat::TYPE_PHP, $now, $updateDateForMajor);
            $this->createOrUpdateMainRecord($package, PhpStat::TYPE_PLATFORM, $now, $updateDateForMajor);
        }
    }

    /**
     * @param array<string, int> $keys array of keys => dl count
     */
    private function createDbRecordsForKeys(Package $package, array $keys, DateTimeImmutable $now): bool
    {
        reset($keys);
        $info = $this->getKeyInfo($package, key($keys));

        $majorRecord = null;
        $record = $this->createOrUpdateRecord($package, $info['type'], $info['version'], $keys, $now);
        // create an aggregate major version data point by summing up all the minor versions under it
        if ($record && $record->getDepth() === PhpStat::DEPTH_MINOR && preg_match('{^\d+}', $record->getVersion(), $match)) {
            $majorRecord = $this->createOrUpdateRecord($package, $info['type'], $match[0], $keys, $now);
        }

        return null !== $record || null !== $majorRecord;
    }

    private function createOrUpdateRecord(Package $package, int $type, string $version, array $keys, DateTimeImmutable $now): ?PhpStat
    {
        $record = $this->getEntityManager()->getRepository(PhpStat::class)->findOneBy(['package' => $package, 'type' => $type, 'version' => $version]);

        if (!$record) {
            $record = new PhpStat($package, $type, $version);
        }

        $addedData = false;
        foreach ($keys as $key => $val) {
            if (!$val) {
                continue;
            }

            $pointInfo = $this->getKeyInfo($package, $key);
            if (($pointInfo['version'] !== $version && !str_starts_with($pointInfo['version'], $version)) || $pointInfo['type'] !== $type) {
                throw new \LogicException('Version or type mismatch, somehow the key grouping in buffer failed, got '.json_encode($pointInfo).' and '.json_encode(['type' => $type, 'version' => $version]));
            }
            $record->addDataPoint($pointInfo['phpversion'], $pointInfo['date'], $val);
            $addedData = true;
        }

        if ($addedData) {
            $record->setLastUpdated($now);

            $this->getEntityManager()->persist($record);
            $this->getEntityManager()->flush();

            return $record;
        }

        return null;
    }

    public function createOrUpdateMainRecord(Package $package, int $type, DateTimeImmutable $now, DateTimeImmutable $updateDate): void
    {
        $minorPhpVersions = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT stats.php_minor AS php_minor
            FROM (SELECT DISTINCT JSON_KEYS(p.data) as versions FROM php_stat p WHERE p.package_id = :package AND p.type = :type AND p.depth IN (:exact, :major)) AS x, 
            JSON_TABLE(x.versions, \'$[*]\' COLUMNS (php_minor VARCHAR(191) PATH \'$\')) stats',
            ['package' => $package->getId(), 'type' => $type, 'exact' => PhpStat::DEPTH_EXACT, 'major' => PhpStat::DEPTH_MAJOR]
        );

        if (!$minorPhpVersions) {
            return;
        }

        $record = $this->getEntityManager()->getRepository(PhpStat::class)->findOneBy(['package' => $package, 'type' => $type, 'version' => '']);

        if (!$record) {
            $record = new PhpStat($package, $type, '');
        }

        $sumQueries = [];
        $dataPointDate = $updateDate->format('Ymd');
        foreach ($minorPhpVersions as $index => $version) {
            $sumQueries[] = 'SUM(DATA->\'$."'.$version.'"."'.$dataPointDate.'"\')';
        }
        $sums = $this->getEntityManager()->getConnection()->fetchNumeric(
            'SELECT '.implode(', ', $sumQueries).' FROM php_stat p WHERE p.package_id = :package AND p.type = :type AND p.depth IN (:exact, :major)',
            ['package' => $package->getId(), 'type' => $type, 'exact' => PhpStat::DEPTH_EXACT, 'major' => PhpStat::DEPTH_MAJOR]
        );

        foreach ($minorPhpVersions as $index => $version) {
            if ($sums[$index]) {
                $record->setDataPoint($version, $dataPointDate, $sums[$index]);
            }
        }

        $record->setLastUpdated($now);

        $this->getEntityManager()->persist($record);
        $this->getEntityManager()->flush();
    }

    /**
     * @return array{type: PhpStat::TYPE_*, version: string, phpversion: string, date: string, package: int}
     */
    private function getKeyInfo(Package $package, string $key): array
    {
        if (!preg_match('{^php(?<platform>platform)?:(?<package>\d+)-(?<version>.+):(?<phpversion>\d+\.\d+|hhvm):(?<date>\d+)$}', $key, $match)) {
            throw new \LogicException('Could not parse key: '.$key);
        }

        if ((int) $match['package'] !== $package->getId()) {
            throw new \LogicException('Expected keys for package id '.$package->getId().', got '.$key);
        }

        return [
            'type' => $match['platform'] === 'platform' ? PhpStat::TYPE_PLATFORM : PhpStat::TYPE_PHP,
            'version' => $match['version'],
            'phpversion' => $match['phpversion'],
            'date' => $match['date'],
            'package' => (int) $match['package'],
        ];
    }
}
