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

use App\Audit\AuditRecordType;
use App\Audit\TransparencyLogType;
use App\Entity\PackageTransparencyLogQueueRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\NilUlid;

/**
 * Backfills the transparency-log projection queue from audit_log history.
 *
 * A queue row is written at the same time as the audit record itself, so records that predate
 * package_transparency_log_queue have none and are never projected on their own. This command is
 * the only thing that gives them one, and it is safe to re-run: it enqueues every package-native
 * audit_log record that has neither a package_transparency_log entry nor a queue row already.
 *
 * Only package-native types ({@see TransparencyLogType::packageNativeAuditRecordTypes()}) are ever
 * seeded. Those carry the package they belong to, so they project exactly as they happened.
 * Account-security don't carry a package of their own; the projector fans each of them out to
 * every package the affected user maintains at projection time. Seeding an old one would therefore
 * publish it against today's maintainer set instead of the one it happened under, and a published
 * entry cannot be retracted.
 *
 * Seeded records are appended at the end of package_transparency_log (in some cases out of chronological order).
 * Run --dry-run first to see how many there are.
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
            static fn (AuditRecordType $type): string => $type->value,
            TransparencyLogType::packageNativeAuditRecordTypes(),
        );

        $after = new NilUlid();
        $seeded = 0;

        do {
            $ids = $this->queueRepository->fetchSeedableIds($types, $after, self::BATCH_SIZE);

            if ($ids !== []) {
                // Page by the last id seen rather than by rows dropping out of the query, so a dry run
                // makes exactly the same progress as a real one.
                $after = $ids[\count($ids) - 1];
                $seeded += $dryRun ? \count($ids) : $this->queueRepository->enqueueIds($ids);
                $output->writeln(\sprintf('%d so far (up to %s)', $seeded, $after->getDateTime()->format('Y-m-d H:i:s')));
            }
        } while (\count($ids) === self::BATCH_SIZE);

        $output->writeln(\sprintf($dryRun ? 'Done, %d record(s) would be enqueued' : 'Done, %d record(s) enqueued', $seeded));

        return Command::SUCCESS;
    }
}
