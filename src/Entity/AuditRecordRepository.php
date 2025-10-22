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
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * @extends ServiceEntityRepository<AuditRecord>
 */
class AuditRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditRecord::class);
    }

    public function add(AuditRecord $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AuditRecord $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Performs a direct insert not requiring usage of the ORM so it can be used within ORM lifecycle listeners
     */
    public function insert(AuditRecord $record): void
    {
        $this->getEntityManager()->getConnection()->insert('audit_log', [
            'id' => $record->id,
            'datetime' => $record->datetime,
            'type' => $record->type->value,
            'attributes' => $record->attributes,
            'actorId' => $record->actorId,
            'vendor' => $record->vendor,
            'packageId' => $record->packageId,
        ], [
            'id' => UlidType::NAME,
            'datetime' => Types::DATETIME_IMMUTABLE,
            'attributes' => Types::JSON,
        ]);
    }
}
