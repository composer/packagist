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

        try {
            // might be a large-ish dataset coming through here
            ini_set('memory_limit', '1G');

            $redis = $this->getContainer()->get('snc_redis.default_client');
            $now = new \DateTimeImmutable();
            $todaySuffix = ':'.$now->format('Ymd');
            $keysToUpdate = $redis->keys('dl:*:*');

            // skip today datapoints as we will store that to the DB tomorrow
            $keysToUpdate = array_filter($keysToUpdate, function ($key) use ($todaySuffix) {
                return strpos($key, $todaySuffix) === false;
            });

            // sort by package id, then package datapoint first followed by version datapoints
            usort($keysToUpdate, function ($a, $b) {
                $amin = preg_replace('{^(dl:\d+).*}', '$1', $a);
                $bmin = preg_replace('{^(dl:\d+).*}', '$1', $b);

                if ($amin !== $bmin) {
                    return strcmp($amin, $bmin);
                }

                return strcmp($b, $a);
            });

            // buffer keys per package id and process all keys for a given package one by one
            // to reduce SQL load
            $buffer = [];
            $lastPackageId = null;
            while ($keysToUpdate) {
                $key = array_shift($keysToUpdate);
                if (!preg_match('{^dl:(\d+)}', $key, $m)) {
                    $logger->error('Invalid dl key found: '.$key);
                    continue;
                }

                $packageId = (int) $m[1];

                if ($lastPackageId && $lastPackageId !== $packageId) {
                    $logger->debug('Processing package #'.$lastPackageId);
                    $downloadManager->transferDownloadsToDb($lastPackageId, $buffer, $now);
                    $buffer = [];

                    $doctrine->getManager()->clear();

                    if ($signal->isTriggered()) {
                        break;
                    }
                }

                $buffer[] = $key;
                $lastPackageId = $packageId;
            }

            // process last package
            if ($buffer) {
                $downloadManager->transferDownloadsToDb($lastPackageId, $buffer, $now);
            }
        } finally {
            $locker->unlockCommand($this->getName());
        }
    }
}
