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
 * @author Igor Wiedler <igor@wiedler.ch>
 */
class IndexPackagesCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('packagist:index')
            ->setDefinition(array(
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force a re-indexing of all packages'),
                new InputOption('package', null, InputOption::VALUE_NONE, 'Package name to index'),
            ))
            ->setDescription('Indexes packages')
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
        $verbose = $input->getOption('verbose');
        $force = $input->getOption('force');
        $package = $input->getOption('package');

        $doctrine = $this->getContainer()->get('doctrine');
        $solarium = $this->getContainer()->get('solarium.client');

        if ($force && !$package) {
            if ($verbose) {
                $output->writeln('Deleting existing index');
            }

            $update = $solarium->createUpdate();

            $update->addDeleteQuery('*:*');
            $update->addCommit();

            $solarium->update($update);

            $doctrine
                ->getEntityManager()
                ->createQuery('UPDATE PackagistWebBundle:Package p SET p.indexedAt = NULL')
                ->getResult();
        }

        if ($package) {
            $packages = array($doctrine->getRepository('PackagistWebBundle:Package')->findOneByName($input->getOption('package')));
        } elseif ($force) {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->findAll();
        } else {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getStalePackagesForIndexing();
        }

        foreach ($packages as $package) {
            if ($verbose) {
                $output->writeln('Indexing '.$package->getName());
            }

            try {
                $update = $solarium->createUpdate();

                $document = $update->createDocument();
                $document->id = $package->getId();
                $document->name = $package->getName();
                $document->description = $package->getDescription();

                $update->addDocument($document);
                $update->addCommit();

                $package->setIndexedAt(new \DateTime);

                $em = $doctrine->getEntityManager();
                $em->flush();

                $solarium->update($update);
            } catch (\Exception $e) {
                $output->writeln('<error>Exception: '.$e->getMessage().', skipping package '.$package->getName().'.</error>');
            }
        }
    }
}
