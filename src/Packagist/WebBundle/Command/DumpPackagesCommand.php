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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

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
        $force = $input->getOption('force');

        $doctrine = $this->getContainer()->get('doctrine');

        if ($force) {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getFullPackages();
        } else {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getStalePackagesForDumping();
        }

        $lock = $this->getContainer()->getParameter('kernel.cache_dir').'/composer-dumper.lock';
        $timeout = 600;

        // another dumper is still active
        if (file_exists($lock) && filemtime($lock) > time() - $timeout) {
            return;
        }

        touch($lock);
        $this->getContainer()->get('packagist.package_dumper')->dump($packages);
        unlink($lock);
    }
}
