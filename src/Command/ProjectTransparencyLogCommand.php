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

use App\Service\Locker;
use App\Service\TransparencyLogProjector;
use Psr\Log\LoggerInterface;
use Seld\Signal\SignalHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Thin CLI wrapper around {@see TransparencyLogProjector}: run frequently from cron to project
 * package-relevant audit_log rows into the public package transparency log.
 *
 * The command owns the run-orchestration concerns (option parsing, an advisory lock so two runs never
 * overlap, graceful shutdown, progress output); the projection logic itself lives in the service.
 *
 * Only queued records are projected, so this never projects history on its own. Use
 * {@see SeedTransparencyLogQueueCommand} to backfill.
 */
class ProjectTransparencyLogCommand extends Command
{
    private const DEFAULT_MIN_AGE_SECONDS = 300;

    public function __construct(
        private Locker $locker,
        private LoggerInterface $logger,
        private TransparencyLogProjector $projector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:project-transparency-log')
            ->setDescription('Projects package-relevant audit_log rows into the public package transparency log')
            ->addOption(
                'min-event-age-to-project',
                null,
                InputOption::VALUE_REQUIRED,
                'Only project audit records older than this many seconds. The delay lets records committed out of order arrive before their neighbours are projected. Setting it too low will not skip any records when projecting. The records committed later to audit_log are still projected, just at the end of the transparency log.',
                (string) self::DEFAULT_MIN_AGE_SECONDS,
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $minAge = (string) $input->getOption('min-event-age-to-project');
        if (!ctype_digit($minAge)) {
            $output->writeln('<error>Invalid --min-event-age-to-project: expected a non-negative number of seconds</error>');

            return Command::INVALID;
        }

        // Prevent overlapping runs so leaf-index assignment stays single-threaded and gapless.
        if (!$this->locker->lockCommand(__CLASS__)) {
            if ($output->isVerbose()) {
                $output->writeln('Aborting, another projection run is already active');
            }

            return Command::SUCCESS;
        }

        $signal = SignalHandler::create(null, $this->logger);

        try {
            $this->projector->project(
                (int) $minAge,
                $signal,
                static function (int $projected, int $leafIndex) use ($output): void {
                    $output->writeln(\sprintf('%d projected (up to leaf index %d)', $projected, $leafIndex));
                },
            );

            $output->writeln('Done');
        } finally {
            $this->locker->unlockCommand(__CLASS__);
        }

        return Command::SUCCESS;
    }
}
