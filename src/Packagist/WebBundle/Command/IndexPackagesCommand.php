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

use Packagist\WebBundle\Entity\Package;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
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
                new InputArgument('package', InputArgument::OPTIONAL, 'Package name to index'),
            ))
            ->setDescription('Indexes packages in Solr')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $verbose = $input->getOption('verbose');
        $force = $input->getOption('force');
        $package = $input->getArgument('package');

        $doctrine = $this->getContainer()->get('doctrine');
        $solarium = $this->getContainer()->get('solarium.client');

        if ($package) {
            $packages = array(array('id' => $doctrine->getRepository('PackagistWebBundle:Package')->findOneByName($package)->getId()));
        } elseif ($force) {
            $packages = $doctrine->getEntityManager()->getConnection()->fetchAll('SELECT id FROM package ORDER BY id ASC');
        } else {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getStalePackagesForIndexing();
        }

        $ids = array();
        foreach ($packages as $row) {
            $ids[] = $row['id'];
        }

        // clear index before a full-update
        if ($force && !$package) {
            if ($verbose) {
                $output->writeln('Deleting existing index');
            }

            $update = $solarium->createUpdate();
            $update->addDeleteQuery('*:*');
            $update->addCommit();

            $solarium->update($update);
        }

        $total = count($ids);
        $current = 0;

        // update package index
        while ($ids) {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getFullPackages(array_splice($ids, 0, 50));
            $update = $solarium->createUpdate();

            foreach ($packages as $package) {
                $current++;
                if ($verbose) {
                    $output->writeln('['.sprintf('%'.strlen($total).'d', $current).'/'.$total.'] Indexing '.$package->getName());
                }

                try {
                    $document = $update->createDocument();
                    $this->updateDocumentFromPackage($document, $package);
                    $update->addDocument($document);

                    $package->setIndexedAt(new \DateTime);
                } catch (\Exception $e) {
                    $output->writeln('<error>Exception: '.$e->getMessage().', skipping package '.$package->getName().'.</error>');
                }
            }

            $doctrine->getEntityManager()->flush();
            $doctrine->getEntityManager()->clear();
            unset($packages);

            $update->addCommit();
            $solarium->update($update);
        }
    }

    private function updateDocumentFromPackage(\Solarium_Document_ReadWrite $document, Package $package)
    {
        $document->setField('id', $package->getId());
        $document->setField('name', $package->getName());
        $document->setField('description', $package->getDescription());

        $tags = array();
        foreach ($package->getVersions() as $version) {
            foreach ($version->getTags() as $tag) {
                $tags[mb_strtolower($tag->getName(), 'UTF-8')] = true;
            }
        }
        $document->setField('tags', array_keys($tags));
    }
}
