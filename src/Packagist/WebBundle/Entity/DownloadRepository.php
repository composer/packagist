<?php declare(strict_types=1);

namespace Packagist\WebBundle\Entity;

use Doctrine\ORM\EntityRepository;

class DownloadRepository extends EntityRepository
{
    public function deletePackageDownloads(Package $package)
    {
        $conn = $this->getEntityManager()->getConnection();

        $conn->executeUpdate('DELETE FROM download WHERE package_id = :id', ['package_id' => $package->getId()]);
    }
}
