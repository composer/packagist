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

use App\Entity\Download;
use App\Service\Locker;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class CompileStatsCommand extends Command
{
    use \App\Util\DoctrineTrait;

    public function __construct(
        private Client $redis,
        private Locker $locker,
        private ManagerRegistry $doctrine,
        private \Graze\DogStatsD\Client $statsd,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:stats:compile')
            ->setDefinition([])
            ->setDescription('Updates the redis stats indices')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lockAcquired = $this->locker->lockCommand(__CLASS__);
        if (!$lockAcquired) {
            if ($input->getOption('verbose')) {
                $output->writeln('Aborting, another task is running already');
            }

            return 0;
        }

        $this->statsd->increment('nightly_job.start', 1, 1, ['job' => 'compile-stats']);

        $verbose = $input->getOption('verbose');

        $conn = $this->getEM()->getConnection();

        $yesterday = new \DateTime('yesterday 00:00:00');

        // fetch existing ids
        $ids = $conn->fetchFirstColumn('SELECT id FROM package ORDER BY id ASC');
        /** @var list<int> $ids */
        $ids = array_map('intval', $ids);

        if ($verbose) {
            $output->writeln('Writing new trendiness data into redis');
        }

        while ($id = array_shift($ids)) {
            $total = (int) $this->redis->get('dl:'.$id);
            if ($total > 10) {
                $trendiness = $this->sumLastNDays(7, $id, $yesterday, $conn);
            } else {
                $trendiness = 0;
            }

            $this->redis->zadd('downloads:trending:new', [$id => $trendiness]);
            $this->redis->zadd('downloads:absolute:new', [$id => $total]);
        }

        $this->redis->rename('downloads:trending:new', 'downloads:trending');
        $this->redis->rename('downloads:absolute:new', 'downloads:absolute');

        $this->locker->unlockCommand(__CLASS__);
        $this->statsd->increment('nightly_job.end', 1, 1, ['job' => 'compile-stats']);

        return 0;
    }

    protected function sumLastNDays(int $days, int $id, \DateTime $yesterday, Connection $conn): int
    {
        $date = clone $yesterday;
        $row = $conn->fetchAssociative('SELECT data FROM download WHERE id = :id AND type = :type', ['id' => $id, 'type' => Download::TYPE_PACKAGE]);
        if (!$row) {
            return 0;
        }

        $data = json_decode($row['data'], true);
        $sum = 0;
        for ($i = 0; $i < $days; $i++) {
            $sum += $data[$date->format('Ymd')] ?? 0;
            $date->modify('-1day');
        }

        return $sum;
    }
}
