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

use App\Entity\AuditRecord;
use App\Entity\AuditRecordRepository;
use App\Entity\PackageRepository;
use App\Entity\PackageTransparencyLog;
use App\Entity\PackageTransparencyLogQueueRepository;
use App\Entity\PackageTransparencyLogRepository;
use App\Log\TransparencyLogEventType;
use App\Log\TransparencyLogScrubber;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Seld\Signal\SignalHandler;
use Symfony\Component\Uid\Ulid;

/**
 * Projects package-relevant audit_log rows into the public package_transparency_log, scrubbing PII
 * and assigning a gapless leaf index.
 *
 * {@see \App\Entity\PackageTransparencyLogQueue}: a queue row is written in the same
 * transaction as the audit_log row and deleted in the same transaction as the entries projected from
 * it. Old records need an explicit seed ({@see \App\Command\SeedTransparencyLogQueueCommand}).
 *
 * audit_log.id is a ULID created with the record, not when it commits, so a record can commit after
 * newer ones were already projected. It is still queued then, and gets the next leaf index instead
 * of one in between: leafIndex is the order rows were inserted, not the order the events happened.
 * The safety lag only makes this less likely, {@see self::logIfAppendedOutOfOrder()} reports it.
 */
class TransparencyLogProjector
{
    use \App\Util\DoctrineTrait;

    private const BATCH_SIZE = 500;

    /**
     * A failing record stays queued and is retried next run, so we log it and move on. This many in
     * a row means something bigger is broken, not one bad record, so stop.
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
     * @param int                                                   $minEventAgeSeconds         safety-lag window in seconds (records younger than this stay queued for a later run)
     * @param SignalHandler|null                                    $signal                     checked between batches for graceful shutdown
     * @param (callable(int $projected, int $leafIndex): void)|null $onProgress                 called after each non-empty batch
     * @param bool                                                  $suppressOutOfOrderLogging  turns off {@see self::logIfAppendedOutOfOrder()} for runs where appending out of order is expected, such as a backfill
     *
     * @return int the number of transparency-log rows created
     */
    public function project(int $minEventAgeSeconds, ?SignalHandler $signal = null, ?callable $onProgress = null, bool $suppressOutOfOrderLogging = false): int
    {
        $cutoff = (new \DateTimeImmutable())->modify(\sprintf('-%d seconds', $minEventAgeSeconds));
        $em = $this->getEM();

        $leafIndex = $this->transparencyLogRepository->getMaxLeafIndex();
        // Only used for logging. Read once, since within a run we project in ascending ULID order.
        $highestProjected = $this->transparencyLogRepository->getHighestProjectedSourceId();
        $projected = 0;
        $failures = 0;
        $after = null;

        do {
            $pendingIds = $this->queueRepository->fetchPendingIds($after, self::BATCH_SIZE);
            $records = $this->auditRecordRepository->getRecordsByIds($pendingIds);

            foreach ($pendingIds as $id) {
                $after = $id;

                $record = $records[(string) $id] ?? null;
                if ($record === null) {
                    // Only possible if someone deleted the record from the audit log.
                    $this->logger->error('Transparency log queue references a missing audit record, dropping it', ['auditLogId' => (string) $id]);
                    $this->queueRepository->dequeue($id);

                    continue;
                }

                // Too fresh: stays queued for a later run, it is never dropped.
                if ($record->datetime > $cutoff) {
                    continue;
                }

                try {
                    $inserted = $this->projectAndDequeue($record, $leafIndex);
                    $failures = 0;
                } catch (\Throwable $e) {
                    // The queue row stays, so this is retried next run. The order is not guaranteed
                    // anyway, so one bad record must not block the ones behind it.
                    $this->logger->error('Failed to project an audit record into the transparency log', ['auditLogId' => (string) $id, 'exception' => $e]);
                    if (++$failures >= self::MAX_CONSECUTIVE_FAILURES) {
                        throw $e;
                    }

                    continue;
                }

                if ($inserted > 0 && !$suppressOutOfOrderLogging) {
                    $this->logIfAppendedOutOfOrder($record, $highestProjected, $minEventAgeSeconds, $leafIndex + 1);
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
     * Projects one record and dequeues it in one transaction: either every target package gets its
     * entry and the record is dequeued, or nothing happens and it stays queued.
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
     * Projects one record to its target package(s) and returns how many rows it wrote, which is how
     * many leaf indices it used. 0 is not a failure: the caller dequeues an out-of-scope record, or
     * an account event of a user who maintains nothing, because neither can ever be projected.
     */
    private function projectRecord(AuditRecord $record, int $leafIndex): int
    {
        $type = TransparencyLogEventType::fromAuditLogEventType($record->type);
        if ($type === null) {
            // Only reachable from a seed that named a type we do not project.
            return 0;
        }

        $targets = $this->resolveTargets($record, $type);
        if ($targets === []) {
            return 0;
        }

        $scrubbedAttributes = $this->scrubber->scrub($record->type, $record->attributes);

        return $this->insertTargets($record, $type, $targets, $scrubbedAttributes, $leafIndex);
    }

    /**
     * The packages a record is written to. A package-native event uses its own package, an account
     * event every package the user maintains right now.
     *
     * @return list<array{id: int, vendor: string|null, name: string}>
     */
    private function resolveTargets(AuditRecord $record, TransparencyLogEventType $type): array
    {
        if ($type->fansOutToMaintainedPackages()) {
            return $record->userId !== null ? $this->packageRepository->getPackageRefsByMaintainer($record->userId) : [];
        }

        if ($record->packageId === null) {
            // packageId must not be null: MySQL treats NULLs as distinct, so (sourceAuditLogId, NULL)
            // would not clash in source_package_uniq and a retry could append a second leaf for the
            // same event.
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
     * Inserts one entry per target with consecutive leaf indices, all in one flush
     * ({@see PackageTransparencyLogRepository::appendProjectedEntries()}). The caller rolls back on
     * failure, so those indices are free again for the next record and no numbers are skipped.
     *
     * @param list<array{id: int, vendor: string|null, name: string}> $targets
     * @param array<string, mixed>                                    $scrubbedAttributes
     *
     * @return int rows inserted
     */
    private function insertTargets(AuditRecord $record, TransparencyLogEventType $type, array $targets, array $scrubbedAttributes, int $leafIndex): int
    {
        $entries = [];
        foreach ($targets as $offset => $target) {
            $entries[] = PackageTransparencyLog::project(
                $record,
                $type,
                $leafIndex + $offset + 1,
                $scrubbedAttributes,
                $target['id'],
                $target['vendor'],
                $target['name'],
            );
        }

        $this->transparencyLogRepository->appendProjectedEntries($entries);

        return \count($entries);
    }

    /**
     * Warns when the record just appended is older than the newest one already in the log. The gap
     * between the two ULIDs says how late it was, and how long the safety lag would have to be.
     */
    private function logIfAppendedOutOfOrder(AuditRecord $record, ?Ulid $highestProjected, int $minEventAgeSeconds, int $leafIndex): void
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
