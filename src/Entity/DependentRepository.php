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

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Dependent>
 */
class DependentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dependent::class);
    }

    public function updateDependentSuggesters(int $packageId, int $versionId): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->beginTransaction();

        try {
            $this->deletePackageDependentSuggesters($packageId);

            $conn->executeStatement(
                'INSERT INTO dependent (package_id, packageName, type) SELECT DISTINCT :id, packageName, :type FROM link_require WHERE version_id = :version',
                ['id' => $packageId, 'version' => $versionId, 'type' => Dependent::TYPE_REQUIRE]
            );
            $conn->executeStatement(
                'INSERT INTO dependent (package_id, packageName, type) SELECT DISTINCT :id, packageName, :type FROM link_require_dev WHERE version_id = :version',
                ['id' => $packageId, 'version' => $versionId, 'type' => Dependent::TYPE_REQUIRE_DEV]
            );
            $conn->executeStatement(
                'INSERT INTO suggester (package_id, packageName) SELECT DISTINCT :id, packageName FROM link_suggest WHERE version_id = :version',
                ['id' => $packageId, 'version' => $versionId]
            );
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        $conn->commit();
    }

    public function deletePackageDependentSuggesters(int $packageId): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement('DELETE FROM dependent WHERE package_id = :id', ['id' => $packageId]);
        $conn->executeStatement('DELETE FROM suggester WHERE package_id = :id', ['id' => $packageId]);
    }
}
