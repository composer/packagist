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
use Doctrine\DBAL\ArrayParameterType;
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
     * The largest audit_log id ever projected, or null when the log is empty. For logging only: the
     * queue decides what gets projected, so this must never be used to filter.
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
     * Appends all entries of one source record in a single flush, or throws.
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
     * Counts the packages of each account event, for the "and N more packages" link.
     *
     * @param list<PackageTransparencyLog> $entries
     *
     * @return array<string, int> count by source id (the ULID as base32 string)
     */
    public function countPackagesOfAccountEvents(array $entries): array
    {
        $sourceAuditLogIds = [];
        foreach ($entries as $entry) {
            if ($entry->type->fansOutToMaintainedPackages()) {
                $sourceAuditLogIds[(string) $entry->sourceAuditLogId] = $entry->sourceAuditLogId;
            }
        }

        if ($sourceAuditLogIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT sourceAuditLogId, COUNT(*) FROM package_transparency_log WHERE sourceAuditLogId IN (?) GROUP BY sourceAuditLogId',
            [array_map(static fn (Ulid $id): string => $id->toBinary(), array_values($sourceAuditLogIds))],
            [ArrayParameterType::BINARY],
        );

        $counts = [];
        foreach ($rows as $id => $count) {
            $counts[(string) Ulid::fromBinary((string) $id)] = (int) $count;
        }

        return $counts;
    }

    /**
     * Entries in leafIndex order, for the backfill of {@see PackageTransparencyLogSearch}.
     *
     * @return list<PackageTransparencyLog>
     */
    public function findForIndexing(int $afterLeafIndex, int $limit): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.leafIndex > :after')
            ->setParameter('after', $afterLeafIndex)
            ->orderBy('t.leafIndex', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * All entries, newest event first. Ordered by event time rather than leafIndex, so a late or
     * backfilled entry shows up where it happened instead of at the top.
     *
     * With $showAccountEventsOnce, only the entry with the lowest packageId of each account event is
     * shown, as a stand-in for all its packages.
     */
    public function getQueryBuilderForPublicView(bool $showAccountEventsOnce = false): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.datetime', \SortDirection::Descending)
            ->addOrderBy('t.leafIndex', \SortDirection::Descending);

        if ($showAccountEventsOnce) {
            // NOT EXISTS on source_package_uniq stops at the first sibling with a lower packageId.
            $qb->andWhere('NOT EXISTS (SELECT sibling.id FROM '.PackageTransparencyLog::class.' sibling WHERE sibling.sourceAuditLogId = t.sourceAuditLogId AND sibling.packageId < t.packageId)');
        }

        return $qb;
    }
}
