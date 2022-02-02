<?php declare(strict_types=1);

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

    public function deletePackageDownloads(Package $package)
    {
        $conn = $this->getEntityManager()->getConnection();

        $conn->executeStatement('DELETE FROM download WHERE package_id = :id', ['id' => $package->getId()]);
    }

    /**
     * @return array<string, list<array<int|numeric-string, int>>>
     */
    public function findDataByMajorVersion(Package $package, int $majorVersion): array
    {
        $sql = '
            SELECT v.normalizedVersion, d.data
            FROM package_version v
            INNER JOIN download d ON d.id=v.id AND d.type = :versionType
            WHERE v.package_id = :package AND v.development = 0 AND v.normalizedVersion LIKE :majorVersion
        ';

        $stmt = $this->getEntityManager()->getConnection()
            ->executeQuery(
                $sql,
                ['package' => $package->getId(), 'versionType' => Download::TYPE_VERSION, 'majorVersion' => $majorVersion . '.%']
            );
        $result = $stmt->fetchAllAssociative();
        $stmt->free();

        $series = [];
        foreach ($result as $row) {
            $name = Preg::replace('{^(\d+\.\d+)(\.|$).*}', '$1', $row['normalizedVersion']);
            $series[$name][] = $row['data'] ? json_decode($row['data'], true) : [];
        }

        return $series;
    }

    /**
     * @return array<string, list<array<int|numeric-string, int>>>
     */
    public function findDataByMajorVersions(Package $package): array
    {
        $sql = '
            SELECT v.normalizedVersion, d.data
            FROM package_version v
            INNER JOIN download d ON d.id=v.id AND d.type = :versionType
            WHERE v.package_id = :package AND v.development = 0 AND v.normalizedVersion REGEXP "^[0-9]+"
        ';

        $stmt = $this->getEntityManager()->getConnection()
            ->executeQuery(
                $sql,
                ['package' => $package->getId(), 'versionType' => Download::TYPE_VERSION]
            );
        $result = $stmt->fetchAllAssociative();
        $stmt->free();

        $series = [];
        foreach ($result as $row) {
            $name = Preg::replace('{^(\d+)(\.|$).*}', '$1', $row['normalizedVersion']);
            $series[$name][] = $row['data'] ? json_decode($row['data'], true) : [];
        }

        return $series;
    }
}
