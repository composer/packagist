<?php declare(strict_types=1);

namespace App\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

class SecurityAdvisoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityAdvisory::class);
    }

    public function getPackageSecurityAdvisories(string $name): array
    {
        $sql = 'SELECT s.*
            FROM security_advisory s
            WHERE s.packageName = :name
            ORDER BY s.reportedAt DESC, s.id DESC';

        return $this->getEntityManager()->getConnection()
            ->fetchAllAssociative($sql, ['name' => $name]);
    }

    public function searchSecurityAdvisories(array $packageNames, int $updatedSince): array
    {
        $sql = 'SELECT s.packagistAdvisoryId as advisoryId, s.packageName, s.remoteId, s.title, s.link, s.cve, s.affectedVersions, s.source, s.reportedAt, s.composerRepository
            FROM security_advisory s
            WHERE s.updatedAt >= :updatedSince ' .
            (count($packageNames) > 0 ? ' AND s.packageName IN (:packageNames)' : '')
            .' ORDER BY s.id DESC';

        return $this->getEntityManager()->getConnection()
            ->fetchAllAssociative(
                $sql,
                [
                    'packageNames' => $packageNames,
                    'updatedSince' => date('Y-m-d H:i:s', $updatedSince),
                ],
                ['packageNames' => Connection::PARAM_STR_ARRAY]
            );
    }
}
