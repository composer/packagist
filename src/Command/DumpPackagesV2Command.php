<?php

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

use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Package;
use App\Package\V2Dumper;
use App\Service\Locker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class DumpPackagesV2Command extends Command
{
    use \App\Util\DoctrineTrait;

    private V2Dumper $dumper;
    private Locker $locker;
    private ManagerRegistry $doctrine;
    private string $cacheDir;

    public function __construct(V2Dumper $dumper, Locker $locker, ManagerRegistry $doctrine, string $cacheDir)
    {
        $this->dumper = $dumper;
        $this->locker = $locker;
        $this->doctrine = $doctrine;
        $this->cacheDir = $cacheDir;
        parent::__construct();
    }

    protected function configure()
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
        $lockName = $this->getName();
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

        $iterations = $force ? 1 : 120;
        try {
            while ($iterations--) {
                if ($force) {
                    $packages = $this->getEM()->getConnection()->fetchAllAssociative('SELECT id FROM package WHERE replacementPackage != "spam/spam" OR replacementPackage IS NULL ORDER BY id ASC');
                } else {
                    $packages = $this->getEM()->getRepository(Package::class)->getStalePackagesForDumpingV2();
                }

                $ids = [];
                foreach ($packages as $package) {
                    $ids[] = $package['id'];
                }

                if ($ids || $force) {
                    ini_set('memory_limit', -1);
                    gc_enable();

                    $this->dumper->dump($ids, $force, $verbose);
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
