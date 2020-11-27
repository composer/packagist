<?php declare(strict_types=1);

namespace App\Command;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Seld\Signal\SignalHandler;
use App\Entity\Package;
use App\Model\DownloadManager;
use App\Service\Locker;
use Predis\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;

class MigrateDownloadCountsCommand extends Command
{
    private LoggerInterface $logger;
    private Locker $locker;
    private DownloadManager $downloadManager;
    private Client $redis;
    private ManagerRegistry $doctrine;

    public function __construct(LoggerInterface $logger, Locker $locker, ManagerRegistry $doctrine, DownloadManager $downloadManager, Client $redis)
    {
        $this->logger = $logger;
        $this->locker = $locker;
        $this->doctrine = $doctrine;
        $this->redis = $redis;
        $this->downloadManager = $downloadManager;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('packagist:migrate-download-counts')
            ->setDescription('Migrates download counts from redis to mysql')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // another migrate command is still active
        $lockAcquired = $this->locker->lockCommand($this->getName());
        if (!$lockAcquired) {
            if ($input->getOption('verbose')) {
                $output->writeln('Aborting, another task is running already');
            }
            return;
        }

        $signal = SignalHandler::create(null, $this->logger);

        try {
            // might be a large-ish dataset coming through here
            ini_set('memory_limit', '1G');

            $now = new \DateTimeImmutable();
            $todaySuffix = ':'.$now->format('Ymd');
            $keysToUpdate = $this->redis->keys('dl:*:*');

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
                    $this->logger->error('Invalid dl key found: '.$key);
                    continue;
                }

                $packageId = (int) $m[1];

                if ($lastPackageId && $lastPackageId !== $packageId) {
                    $this->logger->debug('Processing package #'.$lastPackageId);
                    $this->downloadManager->transferDownloadsToDb($lastPackageId, $buffer, $now);
                    $buffer = [];

                    $this->doctrine->getManager()->clear();

                    if ($signal->isTriggered()) {
                        break;
                    }
                }

                $buffer[] = $key;
                $lastPackageId = $packageId;
            }

            // process last package
            if ($buffer) {
                $this->downloadManager->transferDownloadsToDb($lastPackageId, $buffer, $now);
            }
        } finally {
            $this->locker->unlockCommand($this->getName());
        }
    }
}
