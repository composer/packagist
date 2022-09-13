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
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @extends ServiceEntityRepository<Vendor>
 */
class VendorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vendor::class);
    }

    public function isVerified(string $vendor): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne('SELECT verified FROM vendor WHERE name = :vendor', ['vendor' => $vendor]);
    }

    public function verify(string $vendor): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO vendor (name, verified) VALUES (:vendor, 1) ON DUPLICATE KEY UPDATE verified=1',
            ['vendor' => $vendor]
        );
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE package SET suspect = NULL WHERE vendor = :vendor',
            ['vendor' => $vendor]
        );
    }
}
