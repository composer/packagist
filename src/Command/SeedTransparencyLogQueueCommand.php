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

namespace App\Command;

use App\Entity\AuditRecordRepository;
use App\Entity\PackageTransparencyLogQueueRepository;
use App\Log\AuditLogEventType;
use App\Log\TransparencyLogEventType;
use App\Service\TransparencyLogProjector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\NilUlid;

/**
 * Backfills the transparency-log projection queue from audit_log history.
 *
 * A queue row is written at the same time as the audit record, so records older than the queue table
 * have none and are never projected on their own. This command backfills the queue. Safe to re-run: it
 * only enqueues records with a packageId and with neither a package_transparency_log entry nor a
 * queue row.
 *
 * Only package-native types ({@see TransparencyLogEventType::packageNativeAuditLogEventTypes()}) are
 * seeded.
 *
 * Seeded records are appended at the end of the log, sometimes out of order.
 *
 * The dry run also reports the records the projector would refuse, so they can be fixed before they
 * are queued ({@see TransparencyLogProjector::validate()}).
 */
class SeedTransparencyLogQueueCommand extends Command
{
    private const BATCH_SIZE = 500;

    private const MAX_EXAMPLE_IDS = 5;

    public function __construct(
        private PackageTransparencyLogQueueRepository $queueRepository,
        private AuditRecordRepository $auditRecordRepository,
        private TransparencyLogProjector $projector,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:seed-transparency-log-queue')
            ->setDescription('Backfills the transparency-log projection queue from audit_log history')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report how many records would be enqueued, and which of them the projector would refuse, without writing anything.',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        if ($dryRun) {
            $output->writeln('<comment>Dry run, nothing will be written</comment>');
        }

        $types = array_map(
            static fn (AuditLogEventType $type): string => $type->value,
            TransparencyLogEventType::packageNativeAuditLogEventTypes(),
        );

        $after = new NilUlid();
        $seeded = 0;
        /** @var array<string, list<string>> $invalid audit ids by reason */
        $invalid = [];

        do {
            $ids = $this->queueRepository->fetchSeedableIds($types, $after, self::BATCH_SIZE);

            if ($ids !== []) {
                // Page by id so a dry run, which writes nothing, walks the same records as a real run.
                $after = $ids[\count($ids) - 1];
                $seeded += $dryRun ? \count($ids) : $this->queueRepository->enqueueIds($ids);

                if ($dryRun) {
                    foreach ($this->auditRecordRepository->getRecordsByIds($ids) as $id => $record) {
                        $reason = $this->projector->validate($record);
                        if ($reason !== null) {
                            $invalid[$record->type->value.': '.$reason][] = $id;
                        }
                    }
                    $this->em->clear();
                }

                $output->writeln(\sprintf('%d so far (up to %s)', $seeded, $after->getDateTime()->format('Y-m-d H:i:s')));
            }
        } while (\count($ids) === self::BATCH_SIZE);

        $output->writeln(\sprintf($dryRun ? 'Done, %d record(s) would be enqueued' : 'Done, %d record(s) enqueued', $seeded));

        if ($invalid === []) {
            return Command::SUCCESS;
        }

        $output->writeln(\sprintf('<error>%d record(s) would fail projection:</error>', array_sum(array_map('count', $invalid))));
        foreach ($invalid as $reason => $ids) {
            $output->writeln(\sprintf(
                '  %d x %s (%s%s)',
                \count($ids),
                $reason,
                implode(', ', \array_slice($ids, 0, self::MAX_EXAMPLE_IDS)),
                \count($ids) > self::MAX_EXAMPLE_IDS ? ', ...' : '',
            ));
        }

        return Command::FAILURE;
    }
}
