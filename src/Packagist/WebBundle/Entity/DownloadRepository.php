<?php declare(strict_types=1);

namespace Packagist\WebBundle\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

class DownloadRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, Download::class);
    }

    public function deletePackageDownloads(Package $package)
    {
        $conn = $this->getEntityManager()->getConnection();

        $conn->executeUpdate('DELETE FROM download WHERE package_id = :id', ['id' => $package->getId()]);
    }
}
