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

use App\Log\TransparencyLogEventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\NilUlid;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<PackageTransparencyLogQueue>
 */
class PackageTransparencyLogQueueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PackageTransparencyLogQueue::class);
    }

    /**
     * Marks a record as pending projection, in the same transaction as the audit_log row, so a
     * record is never committed without a queue row. Idempotent via INSERT IGNORE.
     *
     * Only projectable types are enqueued. Making a type projectable later does not queue its old
     * records, those need a seed ({@see \App\Command\SeedTransparencyLogQueueCommand}).
     */
    public function enqueue(AuditRecord $record): void
    {
        if (TransparencyLogEventType::fromAuditLogEventType($record->type) === null) {
            return;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT IGNORE INTO package_transparency_log_queue (auditLogId) VALUES (?)',
            [$record->id->toBinary()],
        );
    }

    /**
     * Removes a projected record from the queue. The caller must do this in the same transaction as
     * the entries projected from it.
     */
    public function dequeue(Ulid $auditLogId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM package_transparency_log_queue WHERE auditLogId = ?',
            [$auditLogId->toBinary()],
        );
    }

    /**
     * The oldest pending audit ids after $after, in ULID order so leaf assignment is deterministic.
     *
     * $after is only a cursor within one run and is never stored. Without it a record that stays
     * pending (too fresh for the safety lag, or one that threw) would come first in every batch.
     *
     * @return list<Ulid>
     */
    public function fetchPendingIds(?Ulid $after, int $limit): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('q.auditLogId')
            ->from(PackageTransparencyLogQueue::class, 'q')
            ->where('q.auditLogId > :after')
            ->setParameter('after', $after ?? new NilUlid(), UlidType::NAME)
            ->orderBy('q.auditLogId', 'ASC')
            ->setMaxResults($limit);

        /** @var list<array{auditLogId: Ulid}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(static fn (array $row): Ulid => $row['auditLogId'], $rows);
    }

    /**
     * Audit ids of the given types after $after with neither a package_transparency_log entry nor a
     * queue row, for the backfill seed. Paged so seeding does not keep one long transaction open.
     *
     * @param list<string> $types
     *
     * @return list<Ulid>
     */
    public function fetchSeedableIds(array $types, Ulid $after, int $limit): array
    {
        if ($types === []) {
            return [];
        }

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('a.id')
            ->from(AuditRecord::class, 'a')
            ->where('a.type IN (:types)')
            ->andWhere('a.id > :after')
            ->andWhere('NOT EXISTS (SELECT p.id FROM '.PackageTransparencyLog::class.' p WHERE p.sourceAuditLogId = a.id)')
            ->andWhere('NOT EXISTS (SELECT q.auditLogId FROM '.PackageTransparencyLogQueue::class.' q WHERE q.auditLogId = a.id)')
            ->setParameter('types', $types)
            ->setParameter('after', $after, UlidType::NAME)
            ->orderBy('a.id', 'ASC')
            ->setMaxResults($limit);

        /** @var list<array{id: Ulid}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(static fn (array $row): Ulid => $row['id'], $rows);
    }

    /**
     * Marks a batch of audit ids as pending, for seeding. Skips the type filter in
     * {@see self::enqueue()} because the caller already chose the types.
     *
     * @param non-empty-list<Ulid> $auditLogIds
     *
     * @return int rows actually enqueued
     */
    public function enqueueIds(array $auditLogIds): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT IGNORE INTO package_transparency_log_queue (auditLogId) VALUES '
                .implode(', ', array_fill(0, \count($auditLogIds), '(?)')),
            array_map(static fn (Ulid $id): string => $id->toBinary(), $auditLogIds),
        );
    }
}
