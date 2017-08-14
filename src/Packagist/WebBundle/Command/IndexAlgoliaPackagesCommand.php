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
class IndexAlgoliaPackagesCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('algolia:index')
            ->setDefinition(array(
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force a re-indexing of all packages'),
                new InputOption('all', null, InputOption::VALUE_NONE, 'Index all packages without clearing the index first'),
                new InputArgument('package', InputArgument::OPTIONAL, 'Package name to index'),
            ))
            ->setDescription('Indexes packages in Algolia')
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
        $index_name = $this->getContainer()->getParameter('algolia.index_name');


        $deployLock = $this->getContainer()->getParameter('kernel.cache_dir').'/deploy.globallock';
        if (file_exists($deployLock)) {
            if ($verbose) {
                $output->writeln('Aborting, '.$deployLock.' file present');
            }
            return;
        }

        $doctrine = $this->getContainer()->get('doctrine');
        $algolia = $this->getContainer()->get('packagist.algolia.client');
        $index = $algolia->initIndex($index_name);

        $redis = $this->getContainer()->get('snc_redis.default');
        $downloadManager = $this->getContainer()->get('packagist.download_manager');
        $favoriteManager = $this->getContainer()->get('packagist.favorite_manager');

        $lock = new LockHandler('packagist_algolia_indexer');

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

            $index->clearIndex();
        }

        $total = count($ids);
        $current = 0;

        // update package index
        while ($ids) {
            $indexTime = new \DateTime;
            $idsSlice = array_splice($ids, 0, 50);
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->findById($idsSlice);

            $idsToUpdate = [];
            $records = [];

            foreach ($packages as $package) {
                $current++;
                if ($verbose) {
                    $output->writeln('['.sprintf('%'.strlen($total).'d', $current).'/'.$total.'] Indexing '.$package->getName());
                }

                try {
                    $tags_formatted = $this->getTags($doctrine, $package);

                    $records[] = $this->packageToSearchableArray($package, $tags_formatted, $redis, $downloadManager, $favoriteManager);

                    $idsToUpdate[] = $package->getId();
                } catch (\Exception $e) {
                    $output->writeln('<error>Exception: '.$e->getMessage().', skipping package '.$package->getName().'.</error>');

                    continue;
                }

//                $providers = $doctrine->getManager()->getConnection()->fetchAll(
//                    'SELECT lp.packageName
//                        FROM package p
//                        JOIN package_version pv ON p.id = pv.package_id
//                        JOIN link_provide lp ON lp.version_id = pv.id
//                        WHERE p.id = :id
//                        AND pv.development = true
//                        GROUP BY lp.packageName',
//                    ['id' => $package->getId()]
//                );
//                foreach ($providers as $provided) {
//                    $provided = $provided['packageName'];
//                    try {
//                        $document = $update->createDocument();
//                        $document->setField('id', $provided);
//                        $document->setField('name', $provided);
//                        $document->setField('package_name', '');
//                        $document->setField('description', '');
//                        $document->setField('type', 'virtual-package');
//                        $document->setField('trendiness', 100);
//                        $document->setField('repository', '');
//                        $document->setField('abandoned', 0);
//                        $document->setField('replacementPackage', '');
//                        $update->addDocument($document);
//                    } catch (\Exception $e) {
//                        $output->writeln('<error>'.get_class($e).': '.$e->getMessage().', skipping package '.$package->getName().':provide:'.$provided.'</error>');
//                    }
//                }
            }

            try {
                $index->addObjects($records);
            } catch (\Exception $e) {
                $output->writeln('<error>'.get_class($e).': '.$e->getMessage().', occurred while processing packages: '.implode(',', $idsSlice).'</error>');
                continue;
            }

            $doctrine->getManager()->clear();
            unset($packages);

            if ($verbose) {
                $output->writeln('Updating package indexedAt column');
            }

            $this->updateIndexedAt($idsToUpdate, $doctrine, $indexTime->format('Y-m-d H:i:s'));
        }

        $lock->release();
    }

    private function packageToSearchableArray(
        Package $package,
        array $tags,
        $redis,
        DownloadManager $downloadManager,
        FavoriteManager $favoriteManager
    ) {
        $faversCount = $favoriteManager->getFaverCount($package);
        $downloads = $downloadManager->getTotalDownloads($package);
        $download_log = $package['downloads']['monthly'] ? log($package['downloads']['monthly'], 10) : 0;
        $start_log = $package->getGitHubStars() ? log($package->getGitHubStars(), 10) : 0;
        $popularity = round($download_log + $start_log);

        $record = [
            'id' => $package->getId(),
            'objectID' => $package->getName(),
            'name' => $package->getName(),
            'package_organisation' => $package->getVendor(),
            'package_name' => $package->getPackageName(),
            'description' => preg_replace('{[\x00-\x1f]+}u', '', $package->getDescription()),
            'type' => $package->getType(),
            'repository' => $package->getRepository(),
            'language' => $package->getLanguage(),
            'trendiness' => $redis->zscore('downloads:trending', $package->getId()),
            'popularity' => $popularity,
            'meta' => [
                'downloads' => $downloads,
                'download_formatted' => [
                    'total' => number_format($downloads['total'], 0, ',', ' '),
                    'monthly' => number_format($downloads['monthly'], 0, ',', ' '),
                    'daily' => number_format($downloads['daily'], 0, ',', ' '),
                ],
                'favers' => $faversCount,
                'favers_formatted' => number_format($faversCount, 0, ',', ' '),
            ],
        ];

        if ($package->isAbandoned()) {
            $record['abandoned'] = 1;
            $record['replacementPackage'] = $package->getReplacementPackage() ?: '';
        } else {
            $record['abandoned'] = 0;
            $record['replacementPackage'] = '';
        }


        $record['tags'] = $tags;

        return $record;
    }

    private function getTags($doctrine, $package)
    {
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

        return array_map(function ($tag) {
            return mb_strtolower(preg_replace('{[\x00-\x1f]+}u', '', $tag), 'UTF-8');
        }, $tags);
    }

    private function updateIndexedAt($idsToUpdate, $doctrine, $time)
    {
        $retries = 5;
        // retry loop in case of a lock timeout
        while ($retries--) {
            try {
                $doctrine->getManager()->getConnection()->executeQuery(
                    'UPDATE package SET indexedAt=:indexed WHERE id IN (:ids)',
                    [
                        'ids' => $idsToUpdate,
                        'indexed' => $time,
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
