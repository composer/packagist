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
use App\Entity\PhpStat;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Seld\Signal\SignalHandler;
use App\Service\Locker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;

class RegenerateMainPhpStatsCommand extends Command
{
    use DoctrineTrait;

    public function __construct(
        private LoggerInterface $logger,
        private Locker $locker,
        private ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:regenerate-main-php-stats')
            ->setDescription('Regenerates main php stats for a given data point')
            ->addArgument('date', InputArgument::REQUIRED, 'Data point date YYYYMMDD format')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // another migrate command is still active
        $lockAcquired = $this->locker->lockCommand(__CLASS__);
        if (!$lockAcquired) {
            if ($input->getOption('verbose')) {
                $output->writeln('Aborting, another task is running already');
            }

            return 0;
        }

        $signal = SignalHandler::create(null, $this->logger);

        try {
            // might be a large-ish dataset coming through here
            ini_set('memory_limit', '2G');

            $now = new \DateTimeImmutable();
            $dataPoint = new \DateTimeImmutable($input->getArgument('date'));
            $todaySuffix = ':'.$now->format('Ymd');
            $idsToUpdate = $this->getEM()->getConnection()->fetchFirstColumn(
                'SELECT package_id FROM php_stat WHERE type=:type AND depth=:depth',
                ['type' => PhpStat::TYPE_PHP, 'depth' => PhpStat::DEPTH_MAJOR]
            );

            $phpStatRepo = $this->getEM()->getRepository(PhpStat::class);
            $packageRepo = $this->getEM()->getRepository(Package::class);

            while ($idsToUpdate) {
                $id = array_shift($idsToUpdate);
                $package = $packageRepo->find($id);
                if (!$package) {
                    continue;
                }

                $this->logger->debug('Processing package #'.$id);
                $phpStatRepo->createOrUpdateMainRecord($package, PhpStat::TYPE_PHP, $now, $dataPoint);
                $phpStatRepo->createOrUpdateMainRecord($package, PhpStat::TYPE_PLATFORM, $now, $dataPoint);

                $this->getEM()->clear();

                if ($signal->isTriggered()) {
                    break;
                }
            }
        } finally {
            $this->locker->unlockCommand(__CLASS__);
        }

        return 0;
    }
}
