<?php

declare(strict_types=1);

namespace App\Command;

use App\Package\PackageDumper;
use App\Service\Locker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DumpFullPackagesCommand extends Command
{
    private PackageDumper $packageDumper;
    private Locker $locker;
    private string $cacheDir;

    public function __construct(PackageDumper $packageDumper, Locker $locker, string $cacheDir)
    {
        $this->packageDumper = $packageDumper;
        $this->locker = $locker;
        $this->cacheDir = $cacheDir;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('packagist:dump-full')
            ->setDescription('Dumps the packages with full information into a packages-full.json')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = (bool) $input->getOption('verbose');

        $deployLock = $this->cacheDir.'/deploy.globallock';
        if (file_exists($deployLock)) {
            if ($verbose) {
                $output->writeln('Aborting, '.$deployLock.' file present');
            }
            return 0;
        }

        $lockName = $this->getName();

        if (! $this->locker->lockCommand($lockName)) {
            if ($verbose) {
                $output->writeln('Aborting, another task is running already');
            }
            return 0;
        }

        try {
            $this->packageDumper->dump();
        } finally {
            $this->locker->unlockCommand($lockName);
        }

        return 0;
    }
}
