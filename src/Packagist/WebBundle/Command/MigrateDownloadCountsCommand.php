<?php declare(strict_types=1);

namespace Packagist\WebBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\LockHandler;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Seld\Signal\SignalHandler;
use Packagist\WebBundle\Entity\Package;

class MigrateDownloadCountsCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('packagist:migrate-download-counts')
            ->setDescription('Migrates download counts from redis to mysql')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $locker = $this->getContainer()->get('locker');
        $logger = $this->getContainer()->get('logger');

        // another migrate command is still active
        $lockAcquired = $locker->lockCommand($this->getName());
        if (!$lockAcquired) {
            if ($input->getOption('verbose')) {
                $output->writeln('Aborting, another task is running already');
            }
            return;
        }

        $signal = SignalHandler::create(null, $logger);
        $downloadManager = $this->getContainer()->get('packagist.download_manager');
        $doctrine = $this->getContainer()->get('doctrine');
        $packageRepo = $doctrine->getRepository(Package::class);

        try {
            $packagesToProcess = $packageRepo->iterateStaleDownloadCountPackageIds();
            foreach ($packagesToProcess as $packageDetails) {
                $packageId = $packageDetails['id'];
                $logger->debug('Processing package #'.$packageId);
                $package = $packageRepo->findOneById($packageId);
                $downloadManager->transferDownloadsToDb($package, $packageDetails['lastUpdated']);

                $doctrine->getManager()->clear();

                if ($signal->isTriggered()) {
                    break;
                }
            }
        } finally {
            $locker->unlockCommand($this->getName());
        }
    }
}
