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
        // Driven off download.package_id so that only versions which actually have a download row get
        // looked up, and in ascending id order rather than normalizedVersion order.
        $sql = '
            SELECT v.normalizedVersion, d.data
            FROM download d
            INNER JOIN package_version v ON v.id = d.id
            WHERE d.package_id = :package AND d.type = :versionType
                AND v.development = 0 AND v.normalizedVersion LIKE :majorVersion
        ';

        return $this->sumDataPerSeries(
            $sql,
            ['package' => $package->getId(), 'versionType' => Download::TYPE_VERSION, 'majorVersion' => $majorVersion.'.%'],
            '{^(\d+\.\d+)(\.|$).*}'
        );
    }

    /**
     * @return array<string, array<int|numeric-string, int>> series name (x) => date (Ymd) => downloads
     */
    public function findDataByMajorVersions(Package $package): array
    {
        // Driven off download.package_id so that only versions which actually have a download row get
        // looked up, and in ascending id order rather than normalizedVersion order.
        $sql = '
            SELECT v.normalizedVersion, d.data
            FROM download d
            INNER JOIN package_version v ON v.id = d.id
            WHERE d.package_id = :package AND d.type = :versionType
                AND v.development = 0 AND v.normalizedVersion REGEXP "^[0-9]+"
        ';

        return $this->sumDataPerSeries(
            $sql,
            ['package' => $package->getId(), 'versionType' => Download::TYPE_VERSION],
            '{^(\d+)(\.|$).*}'
        );
    }

    /**
     * Sums the per-version download data of every returned row into one array per series, so that
     * neither the caller nor this method ever holds every version's decoded data at once.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, array<int|numeric-string, int>>
     */
    private function sumDataPerSeries(string $sql, array $params, string $seriesPattern): array
    {
        $stmt = $this->getEntityManager()->getConnection()->executeQuery($sql, $params);

        $series = [];
        foreach ($stmt->iterateAssociative() as $row) {
            $name = Preg::replace($seriesPattern, '$1', (string) $row['normalizedVersion']);
            // A series whose rows all carry empty data still has to show up, as an all-zero line
            $series[$name] ??= [];

            $data = json_decode((string) $row['data'], true);
            if (!\is_array($data)) {
                continue;
            }

            foreach ($data as $date => $downloads) {
                $series[$name][$date] = ($series[$name][$date] ?? 0) + (int) $downloads;
            }
        }
        $stmt->free();

        return $series;
    }
}
