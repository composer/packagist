<?php declare(strict_types=1);

namespace App\Command;

use App\Entity\PhpStat;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Seld\Signal\SignalHandler;
use App\Model\DownloadManager;
use App\Service\Locker;
use Predis\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;

class MigratePhpStatsCommand extends Command
{
    use DoctrineTrait;

    public function __construct(
        private LoggerInterface $logger,
        private Locker $locker,
        private ManagerRegistry $doctrine,
        private Client $redis,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('packagist:migrate-php-stats')
            ->setDescription('Migrates php stats from redis to mysql')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // another migrate command is still active
        $lockAcquired = $this->locker->lockCommand($this->getName());
        if (!$lockAcquired) {
            if ($input->getOption('verbose')) {
                $output->writeln('Aborting, another task is running already');
            }
            return 0;
        }

        $signal = SignalHandler::create(null, $this->logger);

        try {
            // might be a large-ish dataset coming through here
            ini_set('memory_limit', '1G');

            $now = new \DateTimeImmutable();
            $todaySuffix = ':'.$now->format('Ymd');
            $keysToUpdate = $this->redis->keys('php:*:*:*');
            $keysToUpdate = array_merge($keysToUpdate, $this->redis->keys('phpplatform:*:*:*'));

            // skip today datapoints as we will store that to the DB tomorrow
            $keysToUpdate = array_filter($keysToUpdate, function ($key) use ($todaySuffix) {
                return !str_ends_with($key, $todaySuffix);
            });

            // sort by package id then version
            usort($keysToUpdate, function ($a, $b) {
                $amin = preg_replace('{^php(?:platform)?:(\d+).*}', '$1', $a);
                $bmin = preg_replace('{^php(?:platform)?:(\d+).*}', '$1', $b);

                if ($amin !== $bmin) {
                    return strcmp($amin, $bmin);
                }

                return strcmp($b, $a);
            });

            $phpStatRepo = $this->getEM()->getRepository(PhpStat::class);

            // buffer keys per package id and process all keys for a given package one by one
            // to reduce SQL load
            $buffer = [];
            $lastPackageId = null;
            while ($keysToUpdate) {
                $key = array_shift($keysToUpdate);
                if (!preg_match('{^php(?:platform)?:(\d+)}', $key, $m)) {
                    $this->logger->error('Invalid php key found: '.$key);
                    continue;
                }

                $packageId = (int) $m[1];

                if ($lastPackageId && $lastPackageId !== $packageId) {
                    $this->logger->debug('Processing package #'.$lastPackageId);
                    $phpStatRepo->transferStatsToDb($lastPackageId, $buffer, $now);
                    $buffer = [];

                    $this->getEM()->clear();

                    if ($signal->isTriggered()) {
                        break;
                    }
                }

                $buffer[] = $key;
                $lastPackageId = $packageId;
            }

            // process last package
            if ($buffer) {
                $phpStatRepo->transferStatsToDb($lastPackageId, $buffer, $now);
            }
        } finally {
            $this->locker->unlockCommand($this->getName());
        }

        return 0;
    }
}
