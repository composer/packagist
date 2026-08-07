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

namespace App\Service;

use App\Audit\AuditRecordType;
use App\Audit\TransparencyLogScrubber;
use App\Audit\TransparencyLogType;
use App\Entity\AuditRecord;
use App\Entity\AuditRecordRepository;
use App\Entity\PackageRepository;
use App\Entity\PackageTransparencyLog;
use App\Entity\PackageTransparencyLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Seld\Signal\SignalHandler;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Projects package-relevant audit_log rows into the public package_transparency_log, in ULID
 * (chronological) order, assigning a gapless append-only leaf index and scrubbing PII at write time.
 * The projection is idempotent (unique (sourceAuditLogId, packageId) + INSERT IGNORE).
 *
 * Safety lag: because a row's ULID is assigned when the audit record is constructed but only becomes
 * visible when its transaction commits, a smaller-ULID row can appear *after* a larger one. We only
 * project rows older than the given window so that, by the time the cursor advances past a row, every
 * smaller-ULID row is guaranteed committed and visible. This is correct only if no audit_log-writing
 * transaction stays open longer than the window.
 *
 * Backfill: the cursor only moves forward, so a run that excludes account events leaves the
 * historical ones below the cursor for good. That is intentional — fan-out resolves maintainers at
 * projection time, so an old account event would be published against today's maintainer set.
 */
class TransparencyLogProjector
{
    use \App\Util\DoctrineTrait;

    private const BATCH_SIZE = 500;

    public function __construct(
        private ManagerRegistry $doctrine,
        private TransparencyLogScrubber $scrubber,
        private AuditRecordRepository $auditRecordRepository,
        private PackageTransparencyLogRepository $transparencyLogRepository,
        private PackageRepository $packageRepository,
    ) {
    }

    /**
     * Projects every eligible audit_log row older than the safety-lag window.
     *
     * @param int                                                   $minEventAgeSeconds   safety-lag window in seconds (rows younger than this are left for a later run)
     * @param SignalHandler|null                                    $signal               checked between batches for graceful shutdown
     * @param (callable(int $projected, int $leafIndex): void)|null $onProgress           called after each non-empty batch
     * @param bool                                                  $includeAccountEvents pass false to project package-native events only, as the historical backfill does
     *
     * @return int the number of transparency-log rows created
     */
    public function project(int $minEventAgeSeconds, ?SignalHandler $signal = null, ?callable $onProgress = null, bool $includeAccountEvents = true): int
    {
        $cutoff = (new \DateTimeImmutable())->modify(\sprintf('-%d seconds', $minEventAgeSeconds));
        $em = $this->getEM();

        $cursor = $this->transparencyLogRepository->getProjectionCursor();
        $leafIndex = $this->transparencyLogRepository->getMaxLeafIndex();
        $projected = 0;

        do {
            $records = $this->fetchBatch($cutoff, $cursor, $includeAccountEvents);

            foreach ($records as $record) {
                $cursor = $record->id;
                $inserted = $this->projectRecord($record, $leafIndex);
                // each inserted row consumes exactly one leaf index
                $leafIndex += $inserted;
                $projected += $inserted;
            }

            $em->clear();

            if ($onProgress !== null && $records !== []) {
                $onProgress($projected, $leafIndex);
            }

            if ($signal?->isTriggered()) {
                break;
            }
        } while (\count($records) === self::BATCH_SIZE);

        return $projected;
    }

    /**
     * @return list<AuditRecord>
     */
    private function fetchBatch(\DateTimeImmutable $cutoff, ?Ulid $cursor, bool $includeAccountEvents): array
    {
        $qb = $this->auditRecordRepository->createQueryBuilder('a')
            ->where('a.type IN (:types)')
            ->andWhere('a.datetime <= :cutoff')
            ->setParameter('types', $this->inScopeTypeValues($includeAccountEvents))
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->orderBy('a.id', 'ASC')
            ->setMaxResults(self::BATCH_SIZE);
        if ($cursor !== null) {
            $qb->andWhere('a.id > :cursor')->setParameter('cursor', $cursor, UlidType::NAME);
        }

        /** @var list<AuditRecord> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Projects a single source record to its target package(s). Returns how many rows were
     * created (which is how many leaf indices were consumed).
     */
    private function projectRecord(AuditRecord $record, int $leafIndex): int
    {
        $type = TransparencyLogType::fromAuditRecordType($record->type);
        if ($type === null) {
            // Not projectable; should not happen given the type filter, but stay defensive.
            return 0;
        }

        $targets = $this->resolveTargets($record, $type);
        if ($targets === []) {
            return 0;
        }

        $scrubbedAttributes = $this->scrubber->scrub($record->attributes, $type);
        $connection = $this->getEM()->getConnection();

        // A fan-out must be all-or-nothing: a partial insert would let the cursor
        // (MAX(sourceAuditLogId)) advance past this event and drop the remaining packages. A single
        // insert is already atomic under autocommit.
        $useTransaction = \count($targets) > 1;
        if ($useTransaction) {
            $connection->beginTransaction();
        }

        try {
            $inserted = $this->insertTargets($record, $type, $targets, $scrubbedAttributes, $leafIndex);

            if ($useTransaction) {
                $connection->commit();
            }

            return $inserted;
        } catch (\Throwable $e) {
            if ($useTransaction && $connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    /**
     * The package(s) a source record projects onto: package-native events target their own package;
     * account-security events fan out to every package the user maintains at projection time (none
     * for a user who maintains nothing).
     *
     * @return list<array{id: int|null, vendor: string|null}>
     */
    private function resolveTargets(AuditRecord $record, TransparencyLogType $type): array
    {
        if ($type->fansOutToMaintainedPackages()) {
            return $record->userId !== null ? $this->packageRepository->getPackageRefsByMaintainer($record->userId) : [];
        }

        return [['id' => $record->packageId, 'vendor' => $record->vendor]];
    }

    /**
     * Inserts one entry per target, assigning sequential leaf indices. Only a real insert consumes a
     * leaf index, so INSERT IGNORE on an already-projected (source, package) pair can't leave a gap.
     *
     * @param list<array{id: int|null, vendor: string|null}> $targets
     * @param array<string, mixed>                           $scrubbedAttributes
     *
     * @return int rows actually inserted
     */
    private function insertTargets(AuditRecord $record, TransparencyLogType $type, array $targets, array $scrubbedAttributes, int $leafIndex): int
    {
        $inserted = 0;
        foreach ($targets as $target) {
            $entry = PackageTransparencyLog::project(
                $record,
                $type,
                $leafIndex + $inserted + 1,
                $scrubbedAttributes,
                $target['id'],
                $target['vendor'],
            );

            if ($this->transparencyLogRepository->insertProjected($entry) > 0) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * @return list<string>
     */
    private function inScopeTypeValues(bool $includeAccountEvents): array
    {
        return array_map(
            static fn (AuditRecordType $type): string => $type->value,
            $includeAccountEvents
                ? TransparencyLogType::projectedAuditRecordTypes()
                : TransparencyLogType::packageNativeAuditRecordTypes(),
        );
    }
}
