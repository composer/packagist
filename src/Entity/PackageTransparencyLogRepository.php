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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
     * The largest audit_log id ever projected, or null if package_transparency_log is empty.
     *
     * This is for logging purposes only: the queue decides what gets projected, so this must never be used to filter.
     * {@see \App\Service\TransparencyLogProjector} compares each record against it to detect a late
     * arrival, a source row committed after a newer event had already been projected.
     */
    public function getHighestProjectedSourceId(): ?Ulid
    {
        $max = $this->getEntityManager()->getConnection()
            ->fetchOne('SELECT MAX(sourceAuditLogId) FROM package_transparency_log');

        if ($max === false || $max === null) {
            return null;
        }

        return Ulid::fromBinary(\is_resource($max) ? (string) stream_get_contents($max) : (string) $max);
    }

    /**
     * The highest leafIndex currently in package_transparency_log, or -1 when it is empty (so the
     * next leaf is index 0).
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
     * Idempotently appends a projected entry. Returns 1 when the row was appended, or 0 when this
     * (sourceAuditLogId, packageId) pair was already projected, in which case the caller must not
     * consume the candidate leaf index.
     */
    public function insertProjected(PackageTransparencyLog $entry): int
    {
        try {
            return (int) $this->getEntityManager()->getConnection()->executeStatement(
                'INSERT INTO package_transparency_log
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
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'source_package_uniq')) {
                return 0;
            }

            throw $e;
        }
    }

    /**
     * All entries, most recently inserted first, for the public read view. Leaf index is insertion
     * order rather than chronology, so an audit_log row committed late appears at the top of the page
     * carrying a datetime older than the entries below it; the datetime filters still use event time.
     * {@see TransparencyLogType::temporarilyHiddenTypes()} are projected but not shown, unless
     * $includeHiddenTypes is set (auditors see everything).
     */
    public function getQueryBuilderForPublicView(bool $includeHiddenTypes = false): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')->orderBy('t.leafIndex', 'DESC');

        if ($includeHiddenTypes) {
            return $qb;
        }

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
