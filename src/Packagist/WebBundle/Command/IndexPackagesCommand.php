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
use Packagist\WebBundle\Model\DownloadManager;
use Packagist\WebBundle\Model\FavoriteManager;
use Solarium_Document_ReadWrite;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\LockHandler;
use Doctrine\DBAL\Connection;

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
        $downloadManager = $this->getContainer()->get('packagist.download_manager');
        $favoriteManager = $this->getContainer()->get('packagist.favorite_manager');

        $lock = new LockHandler('packagist_package_indexer');

        // another dumper is still active
        if (!$lock->lock()) {
            if ($verbose) {
                $output->writeln('Aborting, another indexer is still active');
            }
            return;
        }

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
            $indexTime = new \DateTime;
            $idsSlice = array_splice($ids, 0, 50);
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->findById($idsSlice);
            $update = $solarium->createUpdate();

            $indexTimeUpdates = [];

            foreach ($packages as $package) {
                $current++;
                if ($verbose) {
                    $output->writeln('['.sprintf('%'.strlen($total).'d', $current).'/'.$total.'] Indexing '.$package->getName());
                }

                try {
                    $document = $update->createDocument();
                    $tags = $doctrine->getManager()->getConnection()->fetchAll(
                        'SELECT t.name FROM package p
                            JOIN package_version pv ON p.id = pv.package_id
                            JOIN version_tag vt ON vt.version_id = pv.id
                            JOIN tag t ON t.id = vt.tag_id
                            WHERE p.id = :id
                            GROUP BY t.id, t.name',
                        ['id' => $package->getId()]
                    );
                    foreach ($tags as $idx => $tag) {
                        $tags[$idx] = $tag['name'];
                    }
                    $this->updateDocumentFromPackage($document, $package, $tags, $redis, $downloadManager, $favoriteManager);
                    $update->addDocument($document);

                    $indexTimeUpdates[$indexTime->format('Y-m-d H:i:s')][] = $package->getId();
                } catch (\Exception $e) {
                    $output->writeln('<error>Exception: '.$e->getMessage().', skipping package '.$package->getName().'.</error>');
                }

                $providers = $doctrine->getManager()->getConnection()->fetchAll(
                    'SELECT lp.packageName
                        FROM package p
                        JOIN package_version pv ON p.id = pv.package_id
                        JOIN link_provide lp ON lp.version_id = pv.id
                        WHERE p.id = :id
                        AND pv.development = true
                        GROUP BY lp.packageName',
                    ['id' => $package->getId()]
                );
                foreach ($providers as $provided) {
                    $provided = $provided['packageName'];
                    try {
                        $document = $update->createDocument();
                        $document->setField('id', $provided);
                        $document->setField('name', $provided);
                        $document->setField('description', '');
                        $document->setField('type', 'virtual-package');
                        $document->setField('trendiness', 100);
                        $document->setField('repository', '');
                        $document->setField('abandoned', 0);
                        $document->setField('replacementPackage', '');
                        $update->addDocument($document);
                    } catch (\Exception $e) {
                        $output->writeln('<error>'.get_class($e).': '.$e->getMessage().', skipping package '.$package->getName().':provide:'.$provided.'</error>');
                    }
                }
            }

            try {
                $update->addCommit();
                $solarium->update($update);
            } catch (\Exception $e) {
                $output->writeln('<error>'.get_class($e).': '.$e->getMessage().', occurred while processing packages: '.implode(',', $idsSlice).'</error>');
            }

            $doctrine->getManager()->clear();
            unset($packages);

            if ($verbose) {
                $output->writeln('Updating package index times');
            }
            foreach ($indexTimeUpdates as $dt => $idsToUpdate) {
                $retries = 5;
                // retry loop in case of a lock timeout
                while ($retries--) {
                    try {
                        $doctrine->getManager()->getConnection()->executeQuery(
                            'UPDATE package SET indexedAt=:indexed WHERE id IN (:ids)',
                            [
                                'ids' => $idsToUpdate,
                                'indexed' => $dt,
                            ],
                            ['ids' => Connection::PARAM_INT_ARRAY]
                        );
                    } catch (\Exception $e) {
                        if (!$retries) {
                            throw $e;
                        }
                        sleep(2);
                    }
                }
            }
        }

        $lock->release();
    }

    private function updateDocumentFromPackage(
        Solarium_Document_ReadWrite $document,
        Package $package,
        array $tags,
        $redis,
        DownloadManager $downloadManager,
        FavoriteManager $favoriteManager
    ) {
        $document->setField('id', $package->getId());
        $document->setField('name', $package->getName());
        $document->setField('description', preg_replace('{[\x00-\x1f]+}u', '', $package->getDescription()));
        $document->setField('type', $package->getType());
        $document->setField('trendiness', $redis->zscore('downloads:trending', $package->getId()));
        $document->setField('downloads', $downloadManager->getTotalDownloads($package));
        $document->setField('favers', $favoriteManager->getFaverCount($package));
        $document->setField('repository', $package->getRepository());
        $document->setField('language', $package->getLanguage());
        if ($package->isAbandoned()) {
            $document->setField('abandoned', 1);
            $document->setField('replacementPackage', $package->getReplacementPackage() ?: '');
        } else {
            $document->setField('abandoned', 0);
            $document->setField('replacementPackage', '');
        }

        $tags = array_map(function ($tag) {
            return mb_strtolower(preg_replace('{[\x00-\x1f]+}u', '', $tag), 'UTF-8');
        }, $tags);
        $document->setField('tags', $tags);
    }
}
