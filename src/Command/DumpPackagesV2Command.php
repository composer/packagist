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

use App\Entity\Package;
use App\Package\V2Dumper;
use App\Service\Locker;
use Doctrine\Persistence\ManagerRegistry;
use Monolog\Logger;
use Seld\Signal\SignalHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Graze\DogStatsD\Client as StatsDClient;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class DumpPackagesV2Command extends Command
{
    use \App\Util\DoctrineTrait;

    public function __construct(
        private V2Dumper $dumper,
        private Locker $locker,
        private ManagerRegistry $doctrine,
        private string $cacheDir,
        private Logger $logger,
        private StatsDClient $statsd,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:dump-v2')
            ->setDefinition([
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force a dump of all packages'),
                new InputOption('gc', null, InputOption::VALUE_NONE, 'Runs garbage collection of old files'),
            ])
            ->setDescription('Dumps the packages into the p2 directory')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        $gc = (bool) $input->getOption('gc');
        $verbose = (bool) $input->getOption('verbose');

        $deployLock = $this->cacheDir.'/deploy.globallock';
        if (file_exists($deployLock)) {
            if ($verbose) {
                $output->writeln('Aborting, '.$deployLock.' file present');
            }

            return 0;
        }

        // another dumper is still active
        $lockName = $this->getName() ?? __CLASS__;
        if ($gc) {
            $lockName .= '-gc';
        }
        if (!$this->locker->lockCommand($lockName)) {
            if ($verbose) {
                $output->writeln('Aborting, another task is running already');
            }

            return 0;
        }

        if ($gc) {
            try {
                $this->dumper->gc();
            } finally {
                $this->locker->unlockCommand($lockName);
            }

            return 0;
        }

        $signal = $force ? null : SignalHandler::create(null, $this->logger);

        $iterations = $force ? 1 : 120;
        try {
            $this->dumper->dumpRoot($verbose);

            while ($iterations--) {
                if ($force) {
                    $ids = $this->getEM()->getConnection()->fetchFirstColumn('SELECT id FROM package WHERE frozen IS NULL ORDER BY id ASC');
                } else {
                    $ids = $this->getEM()->getRepository(Package::class)->getStalePackagesForDumpingV2();
                    $this->statsd->gauge('packagist.metadata_dump_queue', \count($ids));
                    if (\count($ids) > 2000) {
                        $this->logger->emergency('Huge backlog in packages to be dumped is abnormal', ['count' => \count($ids)]);
                        $ids = array_slice($ids, 0, 2000);
                    }
                }

                if ($ids || $force) {
                    ini_set('memory_limit', -1);
                    gc_enable();

                    $ids = array_map('intval', $ids);

                    $this->dumper->dump($ids, $force, $verbose);

                    $this->logger->reset();
                }

                if ($signal !== null && $signal->isTriggered()) {
                    break;
                }

                if (!$force) {
                    // exit the loop whenever we approach the full minute to ensure it gets restarted promptly
                    if ($iterations < 20 && (int) date('s') > 55) {
                        break;
                    }
                    sleep(2);
                }
            }
        } finally {
            $this->locker->unlockCommand($lockName);
        }

        return 0;
    }
}
