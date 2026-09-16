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

use App\Entity\PackageTransparencyLogQueueRepository;
use App\Log\AuditLogEventType;
use App\Log\TransparencyLogEventType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\NilUlid;

/**
 * Backfills the transparency-log projection queue from audit_log history.
 *
 * A queue row is written at the same time as the audit record, so records older than the queue table
 * have none and are never projected on their own. This command backfilly the queue. Safe to re-run: it
 * only enqueues records with neither a package_transparency_log entry nor a queue row.
 *
 * Only package-native types ({@see TransparencyLogEventType::packageNativeAuditLogEventTypes()}) are
 * seeded.
 *
 * Seeded records are appended at the end of the log, sometimes out of order.
 */
class SeedTransparencyLogQueueCommand extends Command
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private PackageTransparencyLogQueueRepository $queueRepository,
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
                'Report how many records would be enqueued without writing anything.',
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

        do {
            $ids = $this->queueRepository->fetchSeedableIds($types, $after, self::BATCH_SIZE);

            if ($ids !== []) {
                // Page by id so a dry run, which writes nothing, walks the same records as a real run.
                $after = $ids[\count($ids) - 1];
                $seeded += $dryRun ? \count($ids) : $this->queueRepository->enqueueIds($ids);
                $output->writeln(\sprintf('%d so far (up to %s)', $seeded, $after->getDateTime()->format('Y-m-d H:i:s')));
            }
        } while (\count($ids) === self::BATCH_SIZE);

        $output->writeln(\sprintf($dryRun ? 'Done, %d record(s) would be enqueued' : 'Done, %d record(s) enqueued', $seeded));

        return Command::SUCCESS;
    }
}
