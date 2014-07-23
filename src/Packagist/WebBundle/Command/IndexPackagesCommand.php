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
                new InputOption('all', null, InputOption::VALUE_NONE, 'Index all packages without clearing the index first'),
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
        $indexAll = $input->getOption('all');
        $package = $input->getArgument('package');

        $deployLock = $this->getContainer()->getParameter('kernel.cache_dir').'/deploy.globallock';
        if (file_exists($deployLock)) {
            if ($verbose) {
                $output->writeln('Aborting, '.$deployLock.' file present');
            }
            return;
        }

        $doctrine = $this->getContainer()->get('doctrine');
        $solarium = $this->getContainer()->get('solarium.client');
        $redis = $this->getContainer()->get('snc_redis.default');

        $lock = $this->getContainer()->getParameter('kernel.cache_dir').'/composer-indexer.lock';
        $timeout = 600;

        // another dumper is still active
        if (file_exists($lock) && filemtime($lock) > time() - $timeout) {
            if ($verbose) {
                $output->writeln('Aborting, '.$lock.' file present');
            }
            return;
        }

        touch($lock);

        if ($package) {
            $packages = array(array('id' => $doctrine->getRepository('PackagistWebBundle:Package')->findOneByName($package)->getId()));
        } elseif ($force || $indexAll) {
            $packages = $doctrine->getManager()->getConnection()->fetchAll('SELECT id FROM package ORDER BY id ASC');
            $doctrine->getManager()->getConnection()->executeQuery('UPDATE package SET indexedAt = NULL');
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
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getPackagesWithVersions(array_splice($ids, 0, 50));
            $update = $solarium->createUpdate();

            foreach ($packages as $package) {
                $current++;
                if ($verbose) {
                    $output->writeln('['.sprintf('%'.strlen($total).'d', $current).'/'.$total.'] Indexing '.$package->getName());
                }

                try {
                    $document = $update->createDocument();
                    $this->updateDocumentFromPackage($document, $package, $redis);
                    $update->addDocument($document);

                    $package->setIndexedAt(new \DateTime);
                } catch (\Exception $e) {
                    $output->writeln('<error>Exception: '.$e->getMessage().', skipping package '.$package->getName().'.</error>');
                }

                foreach ($package->getVersions() as $version) {
                    // abort when a non-dev version shows up since dev ones are ordered first
                    if (!$version->isDevelopment()) {
                        break;
                    }
                    if (count($provide = $version->getProvide())) {
                        foreach ($version->getProvide() as $provide) {
                            try {
                                $document = $update->createDocument();
                                $document->setField('id', $provide->getPackageName());
                                $document->setField('name', $provide->getPackageName());
                                $document->setField('description', '');
                                $document->setField('type', 'virtual-package');
                                $document->setField('trendiness', 100);
                                $document->setField('repository', '');
                                $document->setField('abandoned', 0);
                                $document->setField('replacementPackage', '');
                                $update->addDocument($document);
                            } catch (\Exception $e) {
                                $output->writeln('<error>Exception: '.$e->getMessage().', skipping package '.$package->getName().':provide:'.$provide->getPackageName().'</error>');
                            }
                        }
                    }
                }
            }

            $doctrine->getManager()->flush();
            $doctrine->getManager()->clear();
            unset($packages);

            $update->addCommit();
            $solarium->update($update);
        }

        unlink($lock);
    }

    private function updateDocumentFromPackage(\Solarium_Document_ReadWrite $document, Package $package, $redis)
    {
        $document->setField('id', $package->getId());
        $document->setField('name', $package->getName());
        $document->setField('description', $package->getDescription());
        $document->setField('type', $package->getType());
        $document->setField('trendiness', $redis->zscore('downloads:trending', $package->getId()));
        $document->setField('repository', $package->getRepository());
        if ($package->isAbandoned()) {
            $document->setField('abandoned', 1);
            $document->setField('replacementPackage', $package->getReplacementPackage() ?: '');
        } else {
            $document->setField('abandoned', 0);
            $document->setField('replacementPackage', '');
        }

        $tags = array();
        foreach ($package->getVersions() as $version) {
            foreach ($version->getTags() as $tag) {
                $tags[mb_strtolower($tag->getName(), 'UTF-8')] = true;
            }
        }
        $document->setField('tags', array_keys($tags));
    }
}
