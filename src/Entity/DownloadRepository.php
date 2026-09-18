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

namespace App\Entity;

use Composer\Pcre\Preg;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Download>
 */
class DownloadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Download::class);
    }

    public function deletePackageDownloads(Package $package): void
    {
        $conn = $this->getEntityManager()->getConnection();

        $conn->executeStatement('DELETE FROM download WHERE package_id = :id', ['id' => $package->getId()]);
    }

    /**
     * @return array<string, array<int|numeric-string, int>> series name (x.y) => date (Ymd) => downloads
     */
    public function findDataByMajorVersion(Package $package, int $majorVersion): array
    {
        // filters on v.package_id, which also gives the LIKE a pkg_ver_idx range over just this
        // major's versions rather than reading the data blob of every version in the package
        $stmt = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT v.normalizedVersion, d.data
                FROM package_version v
                INNER JOIN download d ON d.id = v.id AND d.type = :versionType
                WHERE v.package_id = :package AND v.development = 0 AND v.normalizedVersion LIKE :majorVersion',
            ['package' => $package->getId(), 'versionType' => Download::TYPE_VERSION, 'majorVersion' => $majorVersion.'.%']
        );

        return $this->sumDataPerSeries($stmt, '{^(\d+\.\d+)(\.|$).*}');
    }

    /**
     * @return array<string, array<int|numeric-string, int>> series name (x) => date (Ymd) => downloads
     */
    public function findDataByMajorVersions(Package $package): array
    {
        // filters on v.package_id too: download.package_id is only written when the row is created,
        // so it can go stale and must never be what decides which versions belong to the package
        $stmt = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT v.normalizedVersion, d.data
                FROM package_version v
                INNER JOIN download d ON d.id = v.id AND d.type = :versionType
                WHERE v.package_id = :package AND v.development = 0 AND v.normalizedVersion REGEXP "^[0-9]+"',
            ['package' => $package->getId(), 'versionType' => Download::TYPE_VERSION]
        );

        return $this->sumDataPerSeries($stmt, '{^(\d+)(\.|$).*}');
    }

    /**
     * Sums the download data of every row into one array per series, so only one version's decoded
     * data is held at a time. The raw result set is still buffered by pdo_mysql.
     *
     * Callers run the query themselves so that phpstan-dba can still see the SQL. $seriesPattern
     * must capture the series name in group 1, as a pattern that misses leaves the whole version.
     *
     * @return array<string, array<int|numeric-string, int>>
     */
    private function sumDataPerSeries(Result $stmt, string $seriesPattern): array
    {
        $series = [];
        try {
            foreach ($stmt->iterateAssociative() as $row) {
                $name = Preg::replace($seriesPattern, '$1', $row['normalizedVersion']);
                // a series whose rows all carry empty data still has to show up, as an all-zero line
                $series[$name] ??= [];

                $data = json_decode($row['data'], true);
                if (!\is_array($data)) {
                    continue;
                }

                foreach ($data as $date => $downloads) {
                    // discard rather than cast: a corrupt blob must not pass an array off as 1
                    // download, nor add a date key the caller will never look up
                    if (!Preg::isMatch('{^\d{8}$}', (string) $date) || !is_numeric($downloads)) {
                        continue;
                    }
                    $series[$name][$date] = ($series[$name][$date] ?? 0) + (int) $downloads;
                }
            }
        } finally {
            $stmt->free();
        }

        // the row order is up to the optimizer, so sort here to keep the JSON output deterministic
        uksort($series, static fn (int|string $a, int|string $b): int => version_compare((string) $a, (string) $b));

        return $series;
    }
}
