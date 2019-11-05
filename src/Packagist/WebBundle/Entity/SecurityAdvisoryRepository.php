<?php declare(strict_types=1);

namespace Packagist\WebBundle\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Symfony\Bridge\Doctrine\RegistryInterface;

class SecurityAdvisoryRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, SecurityAdvisory::class);
    }

    public function getPackageSecurityAdvisories($name)
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
//                new QueryCacheProfile(7*86400, 'security_advisories_'.$name.'_'.$offset.'_'.$limit, $this->getEntityManager()->getConfiguration()->getResultCacheImpl())
            );
        $result = $stmt->fetchAll();
        $stmt->closeCursor();

        return $result;
    }
}
