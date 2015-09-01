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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\LockHandler;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class DumpPackagesCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('packagist:dump')
            ->setDefinition(array(
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force a dump of all packages'),
            ))
            ->setDescription('Dumps the packages into a packages.json + included files')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $force = (Boolean) $input->getOption('force');
        $verbose = (Boolean) $input->getOption('verbose');

        $deployLock = $this->getContainer()->getParameter('kernel.cache_dir').'/deploy.globallock';
        if (file_exists($deployLock)) {
            if ($verbose) {
                $output->writeln('Aborting, '.$deployLock.' file present');
            }
            return;
        }

        $doctrine = $this->getContainer()->get('doctrine');

        if ($force) {
            $packages = $doctrine->getManager()->getConnection()->fetchAll('SELECT id FROM package ORDER BY id ASC');
        } else {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getStalePackagesForDumping();
        }

        $ids = array();
        foreach ($packages as $package) {
            $ids[] = $package['id'];
        }
        if (!$ids && !$force) {
            if ($verbose) {
                $output->writeln('Aborting, no packages to dump and not doing a forced run');
            }
            return 0;
        }

        $lock = new LockHandler('packagist_package_dumper');

        ini_set('memory_limit', -1);
        gc_enable();

        // another dumper is still active
        if (!$lock->lock()) {
            if ($verbose) {
                $output->writeln('Aborting, another dumper is still active');
            }
            return;
        }

        $result = $this->getContainer()->get('packagist.package_dumper')->dump($ids, $force, $verbose);
        $lock->release();

        return $result ? 0 : 1;
    }
}
