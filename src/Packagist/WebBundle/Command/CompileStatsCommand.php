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

        // TODO delete this whole block mid-august 2018
        $minMax = $conn->fetchAssoc('SELECT MAX(id) maxId, MIN(id) minId FROM package');
        if (!isset($minMax['minId'])) {
            return 0;
        }

        $ids = range($minMax['minId'], $minMax['maxId']);
        $res = $conn->fetchAssoc('SELECT MIN(createdAt) minDate FROM package');
        $date = new \DateTime($res['minDate']);
        $date->modify('00:00:00');
        $yesterday = new \DateTime('yesterday 00:00:00');

        // after this date no need to compute anymore
        $cutoffDate = new \DateTime('2018-07-31 23:59:59');
        while ($date <= $yesterday && $date <= $cutoffDate) {
            // skip months already computed
            if (null !== $this->getMonthly($date) && $date->format('m') !== $yesterday->format('m')) {
                $date->setDate($date->format('Y'), $date->format('m')+1, 1);
                continue;
            }

            // skip days already computed
            if (null !== $this->getDaily($date) && $date != $yesterday) {
                $date->modify('+1day');
                continue;
            }

            $sum = $this->sum($date->format('Ymd'), $ids);
            $redis->set('downloads:'.$date->format('Ymd'), $sum);

            if ($verbose) {
                $output->writeln('Wrote daily data for '.$date->format('Y-m-d').': '.$sum);
            }

            $nextDay = clone $date;
            $nextDay->modify('+1day');
            // update the monthly total if we just computed the last day of the month or the last known day
            if ($date->format('Ymd') === $yesterday->format('Ymd') || $date->format('Ym') !== $nextDay->format('Ym')) {
                $sum = $this->sum($date->format('Ym'), $ids);
                $redis->set('downloads:'.$date->format('Ym'), $sum);

                if ($verbose) {
                    $output->writeln('Wrote monthly data for '.$date->format('Y-m').': '.$sum);
                }
            }

            $date = $nextDay;
        }
        // TODO end delete here

        // fetch existing ids
        $doctrine = $this->getContainer()->get('doctrine');
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

    // TODO delete all below as well once july data is computed
    protected function sum($date, array $ids)
    {
        $sum = 0;

        while ($ids) {
            $batch = array_splice($ids, 0, 500);
            $keys = array();
            foreach ($batch as $id) {
                $keys[] = 'dl:'.$id.':'.$date;
            }
            $sum += array_sum($res = $this->redis->mget($keys));
        }

        return $sum;
    }

    protected function getMonthly(\DateTime $date)
    {
        return $this->redis->get('downloads:'.$date->format('Ym'));
    }

    protected function getDaily(\DateTime $date)
    {
        return $this->redis->get('downloads:'.$date->format('Ymd'));
    }
}
