<?php

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
 */
class VendorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vendor::class);
    }

    public function isVerified(string $vendor): bool
    {
        $result = $this->getEntityManager()->getConnection()->fetchColumn('SELECT verified FROM vendor WHERE name = :vendor', ['vendor' => $vendor]);

        return $result === '1';
    }

    public function verify(string $vendor)
    {
        $this->getEntityManager()->getConnection()->executeUpdate(
            'INSERT INTO vendor (name, verified) VALUES (:vendor, 1) ON DUPLICATE KEY UPDATE verified=1',
            ['vendor' => $vendor]
        );
        $this->getEntityManager()->getConnection()->executeUpdate(
            'UPDATE package SET suspect = NULL WHERE name LIKE :vendor',
            ['vendor' => $vendor.'/%']
        );
    }
}
