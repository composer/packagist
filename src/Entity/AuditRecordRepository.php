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

use App\Audit\AuditLogSearchType;
use App\Audit\VersionDeletionReason;
use App\Log\AuditLogEventType;
use App\Service\AuditRecordsManager;
use App\Util\IpAddress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<AuditRecord>
 */
class AuditRecordRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly AuditRecordsManager $auditRecordsManager,
        private readonly PackageTransparencyLogQueueRepository $transparencyLogQueue,
    ) {
        parent::__construct($registry, AuditRecord::class);
    }

    /**
     * @return list<AuditRecord>
     */
    public function findForFilterListEntry(string $publicId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.type IN (:types)')
            ->andWhere("JSON_EXTRACT(a.attributes, '$.entry.public_id') = :publicId")
            ->setParameter('types', [
                AuditLogEventType::FilterListEntryAdded->value,
                AuditLogEventType::FilterListEntryDeleted->value,
                AuditLogEventType::FilterListEntryDisabled->value,
                AuditLogEventType::FilterListEntryEnabled->value,
                AuditLogEventType::FilterListEntryEdited->value,
            ])
            ->setParameter('publicId', $publicId)
            ->orderBy('a.datetime', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The most recent manual admin moderation actions for the admin dashboard. Account/package
     * freezes and user deletions are always admin-initiated; version soft-deletes and recoveries are
     * only included when the (previous) deletion reason is an admin one (Hidden / DeletedByAdmin) —
     * maintainer version pulls and the Updater's automatic missing-version handling are excluded.
     *
     * @return list<AuditRecord>
     */
    public function getRecentAdminModeration(int $limit): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.type IN (:alwaysTypes)')
            ->orWhere("(a.type = :softDeleted AND JSON_EXTRACT(a.attributes, '$.reason') IN (:adminVersionReasons))")
            ->orWhere("(a.type = :recovered AND JSON_EXTRACT(a.attributes, '$.previousReason') IN (:adminVersionReasons))")
            ->setParameter('alwaysTypes', [
                AuditLogEventType::UserFrozen->value,
                AuditLogEventType::UserUnfrozen->value,
                AuditLogEventType::UserDeleted->value,
                AuditLogEventType::PackageFrozen->value,
                AuditLogEventType::PackageUnfrozen->value,
            ])
            ->setParameter('softDeleted', AuditLogEventType::VersionSoftDeleted->value)
            ->setParameter('recovered', AuditLogEventType::VersionRecovered->value)
            ->setParameter('adminVersionReasons', [
                VersionDeletionReason::DeletedByAdmin->value,
                VersionDeletionReason::Hidden->value,
            ])
            ->orderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The user's own records of the given types, newest first.
     *
     * Narrows through audit_log_search like the transparency-log filters do, because audit_log.userId
     * has no index and on its own makes the optimizer walk datetime_idx backwards — a whole-table
     * scan for a user who has no such record at all. userId stays in the WHERE for precision: the
     * search index is keyed by name, so a released username would otherwise surface its previous
     * holder's events. The cost of that key is that records written under a previous handle are not
     * returned (the rename itself indexes both handles, so it still shows up).
     *
     * @param list<AuditLogEventType> $types
     *
     * @return list<AuditRecord>
     */
    public function findForUser(User $user, array $types, ?\DateTimeImmutable $since = null, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where(\sprintf('a.id IN (SELECT s.auditLogId FROM %s s WHERE s.type = :searchType AND s.name = :username)', AuditLogSearch::class))
            ->andWhere('a.userId = :userId')
            ->andWhere('a.type IN (:types)')
            ->setParameter('searchType', AuditLogSearchType::User->value)
            ->setParameter('username', $user->getUsernameCanonical())
            ->setParameter('userId', $user->getId())
            ->setParameter('types', array_map(static fn (AuditLogEventType $type): string => $type->value, $types))
            // ULIDs sort by creation time, so this is the datetime order without the filesort
            ->orderBy('a.id', 'DESC');

        if (null !== $since) {
            $qb->andWhere('a.datetime > :since')->setParameter('since', $since);
        }

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        /** @var list<AuditRecord> $records */
        $records = $qb->getQuery()->getResult();

        return $records;
    }

    /**
     * @param list<Ulid> $ids
     *
     * @return array<string, AuditRecord> map of ULID string => record (only ids that exist)
     */
    public function getRecordsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Ulid $id): string => $id->toBinary(), $ids), ArrayParameterType::BINARY);

        $records = [];
        /** @var AuditRecord $record */
        foreach ($qb->getQuery()->getResult() as $record) {
            $records[(string) $record->id] = $record;
        }

        return $records;
    }

    /**
     * Performs a direct insert not requiring usage of the ORM so it can be used within ORM lifecycle listeners
     *
     * The queue row must be written in the same transaction as the audit_log row, or the record can
     * never be projected. Some callers, like {@see \App\Security\TwoFactorAuthManager}, are not in a
     * transaction, so start one here.
     */
    public function insert(AuditRecord $record): void
    {
        $this->auditRecordsManager->enrichWithClientIP($record);

        $connection = $this->getEntityManager()->getConnection();
        $connection->beginTransaction();

        try {
            $this->insertRecord($record);
            $this->indexSearchTerms($record);
            $this->transparencyLogQueue->enqueue($record);
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    private function insertRecord(AuditRecord $record): void
    {
        $this->getEntityManager()->getConnection()->insert('audit_log', [
            'id' => $record->id,
            'datetime' => $record->datetime,
            'type' => $record->type->value,
            'attributes' => $record->attributes,
            'actorId' => $record->actorId,
            'vendor' => $record->vendor,
            'packageId' => $record->packageId,
            'userId' => $record->userId,
            'ip' => IpAddress::stringToBinary($record->ip),
            'organizationId' => $record->organizationId,
        ], [
            'id' => UlidType::NAME,
            'datetime' => Types::DATETIME_IMMUTABLE,
            'attributes' => Types::JSON,
            'organizationId' => UlidType::NAME,
        ]);
    }

    /**
     * Denormalizes the record's searchable names into audit_log_search so the transparency-log
     * user/actor/package filters can do an indexed lookup instead of scanning the JSON attributes.
     *
     * Called from {@see insert()} for the direct-insert path and from the postPersist listener for
     * the ORM path (the two paths are disjoint). Idempotent via INSERT IGNORE on the primary key.
     */
    public function indexSearchTerms(AuditRecord $record): void
    {
        $terms = $record->getSearchTerms();
        if (\count($terms) === 0) {
            return;
        }

        $idBinary = $record->id->toBinary();
        $placeholders = [];
        $params = [];
        foreach ($terms as $term) {
            $placeholders[] = '(?, ?, ?)';
            $params[] = $idBinary;
            $params[] = $term['type'];
            $params[] = $term['name'];
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT IGNORE INTO audit_log_search (auditLogId, type, name) VALUES '.implode(', ', $placeholders),
            $params,
        );
    }
}
