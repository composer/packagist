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

use App\Audit\TransparencyLogScrubber;
use App\Audit\TransparencyLogType;
use App\Entity\AuditRecord;
use App\Entity\AuditRecordRepository;
use App\Entity\PackageRepository;
use App\Entity\PackageTransparencyLog;
use App\Entity\PackageTransparencyLogQueueRepository;
use App\Entity\PackageTransparencyLogRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Seld\Signal\SignalHandler;
use Symfony\Component\Uid\Ulid;

/**
 * Projects package-relevant audit_log rows into the public package_transparency_log, assigning a
 * gapless append-only leaf index and scrubbing PII at write time. The projection is idempotent:
 * unique (sourceAuditLogId, packageId), whose violation is the one error
 * {@see PackageTransparencyLogRepository::insertProjected()} reports as an already-projected 0.
 *
 * audit_log.id is a ULID minted when the {@see AuditRecord} is *constructed*, not when its
 * transaction commits, so a long-running transaction can commit a row whose id is lower than rows
 * committed before it. {@see \App\Entity\PackageTransparencyLogQueue} holds one row per pending record,
 * written in the same transaction as the audit_log row and deleted in the same transaction as the
 * entries projected from it. A record committed late is therefore still queued, and is projected
 * then.
 *
 * The safety lag only affects ordering. audit_log.datetime is minted in the same constructor as the
 * id, so a record whose transaction outlives the window is already past the cutoff the instant it
 * committed; holding events back reorders the near-simultaneous ones and nothing else. A record that
 * arrives once a newer one already has a leaf gets the next leafIndex rather than one in between,
 * and is logged by {@see self::reportLateArrival()}. leafIndex is insertion order, not chronology.
 *
 * Scope is controlled by what is enqueued, so historical events are only ever projected when they
 * are explicitly seeded ({@see \App\Command\SeedTransparencyLogQueueCommand}). Account events must
 * never be seeded from history: fan-out resolves maintainers at projection time, so an old one would
 * be published against today's maintainer set.
 */
class TransparencyLogProjector
{
    use \App\Util\DoctrineTrait;

    private const BATCH_SIZE = 500;

    /**
     * A single record that keeps throwing stays queued and is retried next run, so we log it and
     * move on. This many in a row means the failure is systemic (the database is gone) rather than
     * one poison record, and the run should abort loudly.
     */
    private const MAX_CONSECUTIVE_FAILURES = 10;

    public function __construct(
        private ManagerRegistry $doctrine,
        private TransparencyLogScrubber $scrubber,
        private AuditRecordRepository $auditRecordRepository,
        private PackageTransparencyLogRepository $transparencyLogRepository,
        private PackageTransparencyLogQueueRepository $queueRepository,
        private PackageRepository $packageRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Projects every queued audit record older than the safety-lag window.
     *
     * @param int                                                   $minEventAgeSeconds safety-lag window in seconds (records younger than this stay queued for a later run)
     * @param SignalHandler|null                                    $signal             checked between batches for graceful shutdown
     * @param (callable(int $projected, int $leafIndex): void)|null $onProgress         called after each non-empty batch
     *
     * @return int the number of transparency-log rows created
     */
    public function project(int $minEventAgeSeconds, ?SignalHandler $signal = null, ?callable $onProgress = null): int
    {
        $cutoff = (new \DateTimeImmutable())->modify(\sprintf('-%d seconds', $minEventAgeSeconds));
        $em = $this->getEM();

        $leafIndex = $this->transparencyLogRepository->getMaxLeafIndex();
        // Diagnostic only, read once: we project in ascending ULID order, so no record can be late
        // relative to another record of the same run.
        $highestProjected = $this->transparencyLogRepository->getHighestProjectedSourceId();
        $projected = 0;
        $failures = 0;
        $after = null;

        do {
            $pendingIds = $this->queueRepository->fetchPendingIds($after, self::BATCH_SIZE);
            $records = $this->fetchRecords($pendingIds);

            foreach ($pendingIds as $id) {
                $after = $id;

                $record = $records[(string) $id] ?? null;
                if ($record === null) {
                    // Theoretical if someone would delete a record from audit log
                    $this->logger->error('Transparency log queue references a missing audit record, dropping it', ['auditLogId' => (string) $id]);
                    $this->queueRepository->dequeue($id);

                    continue;
                }

                // Ordering heuristic only: too-fresh records stay queued, they are never dropped.
                if ($record->datetime > $cutoff) {
                    continue;
                }

                try {
                    $this->reportLateArrival($record, $highestProjected, $minEventAgeSeconds, $leafIndex + 1);
                    $inserted = $this->projectAndDequeue($record, $leafIndex);
                    $failures = 0;
                } catch (\Throwable $e) {
                    // The queue row survives, so this is a retry next run rather than a loss, and one
                    // bad record must not hold up every later one now that order is best-effort.
                    $this->logger->error('Failed to project an audit record into the transparency log', ['auditLogId' => (string) $id, 'exception' => $e]);
                    if (++$failures >= self::MAX_CONSECUTIVE_FAILURES) {
                        throw $e;
                    }

                    continue;
                }

                // each inserted row consumes exactly one leaf index
                $leafIndex += $inserted;
                $projected += $inserted;
            }

            $em->clear();

            if ($onProgress !== null && $pendingIds !== []) {
                $onProgress($projected, $leafIndex);
            }

            if ($signal?->isTriggered()) {
                break;
            }
        } while (\count($pendingIds) === self::BATCH_SIZE);

        return $projected;
    }

    /**
     * The audit rows behind a batch of queued ids, keyed by ULID string. One primary-key batch
     * lookup, so nothing ever scans audit_log.
     *
     * The safety lag is deliberately not applied here: leaving it out is what makes an id missing
     * from the result unambiguously an orphan, rather than conflating "does not exist" with "too
     * fresh to project yet" and dequeueing live records.
     *
     * @param list<Ulid> $ids
     *
     * @return array<string, AuditRecord>
     */
    private function fetchRecords(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $qb = $this->auditRecordRepository->createQueryBuilder('a')
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
     * Projects one source record and removes it from the queue, atomically: either every fan-out
     * target is published and the record is dequeued, or nothing happened and it is still pending.
     * A partial insert followed by a dequeue would drop the remaining packages for good.
     *
     * Inserts run before the dequeue so the queue row's lock, which every audit writer in the app
     * contends for, is held as briefly as possible.
     */
    private function projectAndDequeue(AuditRecord $record, int $leafIndex): int
    {
        $connection = $this->getEM()->getConnection();
        $connection->beginTransaction();

        try {
            $inserted = $this->projectRecord($record, $leafIndex);
            $this->queueRepository->dequeue($record->id);
            $connection->commit();

            return $inserted;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Projects a single source record to its target package(s). Returns how many rows were
     * created (which is how many leaf indices were consumed).
     *
     * Returning 0 is not a failure: an out-of-scope record and an account event whose user maintains
     * nothing are both dequeued by the caller, because neither will ever become projectable.
     */
    private function projectRecord(AuditRecord $record, int $leafIndex): int
    {
        $type = TransparencyLogType::fromAuditRecordType($record->type);
        if ($type === null) {
            // Only reachable from a seed that named a type we do not project.
            return 0;
        }

        $targets = $this->resolveTargets($record, $type);
        if ($targets === []) {
            return 0;
        }

        $scrubbedAttributes = $this->scrubber->scrub($record->attributes);

        return $this->insertTargets($record, $type, $targets, $scrubbedAttributes, $leafIndex);
    }

    /**
     * The package(s) a source record projects onto: package-native events target their own package;
     * account-security events fan out to every package the user maintains at projection time (none
     * for a user who maintains nothing).
     * @return list<array{id: int, vendor: string|null, name: string}>
     */
    private function resolveTargets(AuditRecord $record, TransparencyLogType $type): array
    {
        if ($type->fansOutToMaintainedPackages()) {
            return $record->userId !== null ? $this->packageRepository->getPackageRefsByMaintainer($record->userId) : [];
        }

        if ($record->packageId === null) {
            // A package-native event without a package cannot be published per-package, and
            // (sourceAuditLogId, NULL) does not collide in source_package_uniq because MySQL treats
            // NULLs as distinct, so a retried projection would append a second, permanently
            // immutable leaf for the same event. Refuse instead, and let the caller dequeue it.
            $this->logger->error('Refusing to project a package-native audit record with no package', [
                'auditLogId' => (string) $record->id,
                'type' => $record->type->value,
            ]);

            return [];
        }

        $name = $record->attributes['name'] ?? null;
        if (!\is_string($name) || $name === '') {
            $this->logger->error('Refusing to project a package-native audit record with no package name', [
                'auditLogId' => (string) $record->id,
                'type' => $record->type->value,
            ]);

            return [];
        }

        return [['id' => $record->packageId, 'vendor' => $record->vendor, 'name' => $name]];
    }

    /**
     * Inserts one entry per target, assigning sequential leaf indices. Only a real insert consumes a
     * leaf index, so a rolled-back record advances nothing and frees its candidate indices for the
     * next one, which is what keeps the sequence gapless.
     *
     * The duplicate-key error insertProjected() swallows does not poison the enclosing transaction:
     * InnoDB rolls back only the offending statement, so the remaining targets still commit.
     *
     * @param list<array{id: int, vendor: string|null, name: string}> $targets
     * @param array<string, mixed>                                    $scrubbedAttributes
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
                $target['name'],
            );

            if ($this->transparencyLogRepository->insertProjected($entry) > 0) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * Logs a record committed after a newer event had already been projected. Both ULIDs are
     * created at AuditRecord construction, so the delta is how far back in event time this entry lands
     * behind the newest already-published one, which is what the safety lag would have had to be to
     * publish it in order.
     */
    private function reportLateArrival(AuditRecord $record, ?Ulid $highestProjected, int $minEventAgeSeconds, int $leafIndex): void
    {
        if ($highestProjected === null || $record->id->compare($highestProjected) >= 0) {
            return;
        }

        $behind = (float) $highestProjected->getDateTime()->format('U.u') - (float) $record->id->getDateTime()->format('U.u');

        $this->logger->warning('Late-arriving audit record appended at the tip of the transparency log', [
            'auditLogId' => (string) $record->id,
            'type' => $record->type->value,
            'behindNewestProjectedSeconds' => round($behind, 3),
            'safetyLagSeconds' => $minEventAgeSeconds,
            'leafIndex' => $leafIndex,
        ]);
    }
}
