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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ClearVersionsCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('packagist:clear:versions')
            ->setDefinition(array(
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force execution, by default it runs in dry-run mode'),
                new InputOption('filter', null, InputOption::VALUE_NONE, 'Filter (regex) against "<version name> <version number>"'),
            ))
            ->setDescription('Clears all versions from the databases')
            ->setHelp(<<<EOF

EOF
            )
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $force = $input->getOption('force');
        $filter = $input->getOption('filter');
        $doctrine = $this->getContainer()->get('doctrine');

        $versionRepo = $doctrine->getRepository('PackagistWebBundle:Version');

        $packages = $doctrine->getManager()->getConnection()->fetchAll('SELECT id FROM package ORDER BY id ASC');
        $ids = array();
        foreach ($packages as $package) {
            $ids[] = $package['id'];
        }

        $packageNames = array();

        while ($ids) {
            $qb = $versionRepo->createQueryBuilder('v');
            $qb
                ->join('v.package', 'p')
                ->where($qb->expr()->in('p.id', array_splice($ids, 0, 50)));
            $versions = $qb->getQuery()->iterate();

            foreach ($versions as $version) {
                $version = $version[0];
                $name = $version->getName().' '.$version->getVersion();
                if (!$filter || preg_match('{'.$filter.'}i', $name)) {
                    $output->writeln('Clearing '.$name);
                    if ($force) {
                        $packageNames[] = $version->getName();
                        $versionRepo->remove($version);
                    }
                }
            }

            $doctrine->getManager()->flush();
            $doctrine->getManager()->clear();
            unset($versions);
        }

        if ($force) {
            // mark packages as recently crawled so that they get updated
            $packageRepo = $doctrine->getRepository('PackagistWebBundle:Package');
            foreach ($packageNames as $name) {
                $package = $packageRepo->findOneByName($name);
                $package->setCrawledAt(new \DateTime);
            }

            $doctrine->getManager()->flush();
        }
    }
}
