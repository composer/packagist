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
        // package_version drives this one: the LIKE turns pkg_ver_idx into a range over just this
        // major's versions, rather than reading the data blob of every version in the package
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
        // download drives this one, as the REGEXP spans every major so there is no range to gain and
        // versions without a download row get skipped; v.package_id is still what decides ownership
        $stmt = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT v.normalizedVersion, d.data
                FROM download d
                INNER JOIN package_version v ON v.id = d.id
                WHERE d.package_id = :package AND d.type = :versionType
                    AND v.package_id = :package AND v.development = 0 AND v.normalizedVersion REGEXP "^[0-9]+"',
            ['package' => $package->getId(), 'versionType' => Download::TYPE_VERSION]
        );

        return $this->sumDataPerSeries($stmt, '{^(\d+)(\.|$).*}');
    }

    /**
     * Sums the download data of every row into one array per series, so that neither this method nor
     * the caller ever holds every version's decoded data at once.
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
                    // skip instead of casting, so a corrupt blob cannot pass off an array as 1 download
                    if (!is_numeric($downloads)) {
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
