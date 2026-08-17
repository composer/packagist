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

use App\Audit\TransparencyLogType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<PackageTransparencyLog>
 */
class PackageTransparencyLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PackageTransparencyLog::class);
    }

    /**
     * The projection cursor: the largest source audit_log id already projected, or null if the log is
     * empty. The next run resumes after it (WHERE id > cursor). ULIDs sort chronologically, so MAX()
     * is the last-projected event.
     */
    public function getProjectionCursor(): ?Ulid
    {
        $max = $this->getEntityManager()->getConnection()
            ->fetchOne('SELECT MAX(sourceAuditLogId) FROM package_transparency_log');

        if ($max === false || $max === null) {
            return null;
        }

        return Ulid::fromBinary(\is_resource($max) ? (string) stream_get_contents($max) : (string) $max);
    }

    /**
     * The highest leaf index currently in the log, or -1 when empty (so the next leaf is index 0).
     */
    public function getMaxLeafIndex(): int
    {
        $max = $this->getEntityManager()->getConnection()
            ->fetchOne('SELECT MAX(leafIndex) FROM package_transparency_log');

        if ($max === false || $max === null) {
            return -1;
        }

        return (int) $max;
    }

    /**
     * Idempotently appends a projected entry. Returns the number of affected rows: 0 means the source
     * row was already projected (unique sourceAuditLogId) and the insert was ignored, so the caller
     * must not consume the candidate leaf index.
     */
    public function insertProjected(PackageTransparencyLog $entry): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT IGNORE INTO package_transparency_log
                (id, sourceAuditLogId, leafIndex, type, attributes, datetime, actorId, vendor, packageId, userId, organizationId, leafHash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $entry->id->toBinary(),
                $entry->sourceAuditLogId->toBinary(),
                $entry->leafIndex,
                $entry->type->value,
                json_encode($entry->attributes, \JSON_THROW_ON_ERROR),
                $entry->datetime->format('Y-m-d H:i:s'),
                $entry->actorId,
                $entry->vendor,
                $entry->packageId,
                $entry->userId,
                $entry->organizationId?->toBinary(),
                null,
            ],
        );
    }

    /**
     * All entries, newest first (leaf index is chronological), for the public read view.
     * {@see TransparencyLogType::temporarilyHiddenTypes()} are projected but not shown.
     */
    public function getQueryBuilderForPublicView(): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')->orderBy('t.leafIndex', 'DESC');

        $hiddenTypes = array_map(
            static fn (TransparencyLogType $type): string => $type->value,
            TransparencyLogType::temporarilyHiddenTypes(),
        );
        if ($hiddenTypes !== []) {
            $qb->andWhere('t.type NOT IN (:hiddenTypes)')
                ->setParameter('hiddenTypes', $hiddenTypes);
        }

        return $qb;
    }
}
