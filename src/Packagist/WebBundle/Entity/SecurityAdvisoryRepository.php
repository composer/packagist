<?php declare(strict_types=1);

namespace Packagist\WebBundle\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\RegistryInterface;

class SecurityAdvisoryRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, SecurityAdvisory::class);
    }

    public function getPackageSecurityAdvisories(string $name): array
    {
        $sql = 'SELECT s.*
            FROM security_advisory s
            WHERE s.packageName = :name
            ORDER BY s.id DESC';

        $stmt = $this->getEntityManager()->getConnection()
            ->executeQuery(
                $sql,
                ['name' => $name],
                []
            );
        $result = $stmt->fetchAll();
        $stmt->closeCursor();

        return $result;
    }

    public function searchSecurityAdvisories(array $packageNames, int $updatedSince): array
    {
        $sql = 'SELECT s.packageName, s.remoteId, s.title, s.link, s.cve, s.affectedVersions, s.source
            FROM security_advisory s
            WHERE s.updatedAt >= :updatedSince ' .
            (count($packageNames) > 0 ? ' AND s.packageName IN (:packageNames)' : '')
            .' ORDER BY s.id DESC';

        $stmt = $this->getEntityManager()->getConnection()
            ->executeQuery(
                $sql,
                [
                    'packageNames' => $packageNames,
                    'updatedSince' => (new \DateTime('@' . $updatedSince))->format('Y-m-d H:i:s'),
                ],
                ['packageNames' => Connection::PARAM_STR_ARRAY]
            );
        $result = $stmt->fetchAll();
        $stmt->closeCursor();

        return $result;
    }
}
