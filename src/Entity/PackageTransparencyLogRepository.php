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
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<PackageTransparencyLog>
 */
class PackageTransparencyLogRepository extends ServiceEntityRepository
{
    public function __construct(private ManagerRegistry $doctrine)
    {
        parent::__construct($doctrine, PackageTransparencyLog::class);
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
     * Appends all entries of one source record in a single flush, or throws. A source_package_uniq
     * violation is a failure like any other: the queue row is deleted in the same transaction as the
     * entries, so an already projected record should never be projected again.
     *
     * One flush, not one per entry, because every flush recomputes the change set of all entities
     * the UnitOfWork holds. An account event of a maintainer of N packages would otherwise cost
     * O(N^2) inside one open transaction. Entries are immutable and never read back, so they are
     * detached once written.
     *
     * @param list<PackageTransparencyLog> $entries
     */
    public function appendProjectedEntries(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $em = $this->doctrine->getManager();

        try {
            foreach ($entries as $entry) {
                $em->persist($entry);
            }
            $em->flush();

            foreach ($entries as $entry) {
                $em->detach($entry);
            }
        } catch (\Throwable $e) {
            $this->doctrine->resetManager();

            throw $e;
        }
    }

    /**
     * All entries, most recently inserted first, for the public read view. Leaf index is insertion
     * order rather than chronology, so an audit_log row committed late appears at the top of the page
     * carrying a datetime older than the entries below it; the datetime filters still use event time.
     * {@see TransparencyLogEventType::temporarilyHiddenTypes()} are projected but not shown, unless
     * $includeHiddenTypes is set (auditors see everything).
     */
    public function getQueryBuilderForPublicView(bool $includeHiddenTypes = false): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')->orderBy('t.leafIndex', 'DESC');

        if ($includeHiddenTypes) {
            return $qb;
        }

        $hiddenTypes = array_map(
            static fn (TransparencyLogEventType $type): string => $type->value,
            TransparencyLogEventType::temporarilyHiddenTypes(),
        );
        if ($hiddenTypes !== []) {
            $qb->andWhere('t.type NOT IN (:hiddenTypes)')
                ->setParameter('hiddenTypes', $hiddenTypes);
        }

        return $qb;
    }
}
