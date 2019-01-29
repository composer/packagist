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

namespace Packagist\WebBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Packagist\WebBundle\Entity\Download;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class CompileStatsCommand extends ContainerAwareCommand
{
    protected $redis;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('packagist:stats:compile')
            ->setDefinition(array(
            ))
            ->setDescription('Updates the redis stats indices')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $verbose = $input->getOption('verbose');

        $doctrine = $this->getContainer()->get('doctrine');
        $conn = $doctrine->getManager()->getConnection();
        $this->redis = $redis = $this->getContainer()->get('snc_redis.default');

        $yesterday = new \DateTime('yesterday 00:00:00');

        // fetch existing ids
        $packages = $conn->fetchAll('SELECT id FROM package ORDER BY id ASC');
        $ids = array();
        foreach ($packages as $row) {
            $ids[] = (int) $row['id'];
        }

        if ($verbose) {
            $output->writeln('Writing new trendiness data into redis');
        }

        while ($id = array_shift($ids)) {
            $total = (int) $redis->get('dl:'.$id);
            if ($total > 10) {
                $trendiness = $this->sumLastNDays(7, $id, $yesterday, $conn);
            } else {
                $trendiness = 0;
            }

            $redis->zadd('downloads:trending:new', $trendiness, $id);
            $redis->zadd('downloads:absolute:new', $total, $id);
        }

        $redis->rename('downloads:trending:new', 'downloads:trending');
        $redis->rename('downloads:absolute:new', 'downloads:absolute');
    }

    protected function sumLastNDays($days, $id, \DateTime $yesterday, $conn)
    {
        $date = clone $yesterday;
        $row = $conn->fetchAssoc('SELECT data FROM download WHERE id = :id AND type = :type', ['id' => $id, 'type' => Download::TYPE_PACKAGE]);
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
