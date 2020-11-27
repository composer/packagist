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

namespace App\Command;

use Algolia\AlgoliaSearch\SearchClient;
use App\Entity\Package;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use App\Service\Locker;
use Predis\Client;
use Symfony\Component\Console\Command\Command;

class IndexPackagesCommand extends Command
{
    private SearchClient $algolia;
    private Locker $locker;
    private ManagerRegistry $doctrine;
    private Client $redis;
    private DownloadManager $downloadManager;
    private FavoriteManager $favoriteManager;
    private string $algoliaIndexName;
    private string $cacheDir;

    public function __construct(SearchClient $algolia, Locker $locker, ManagerRegistry $doctrine, Client $redis, DownloadManager $downloadManager, FavoriteManager $favoriteManager, string $algoliaIndexName, string $cacheDir)
    {
        $this->algolia = $algolia;
        $this->locker = $locker;
        $this->doctrine = $doctrine;
        $this->redis = $redis;
        $this->downloadManager = $downloadManager;
        $this->favoriteManager = $favoriteManager;
        $this->algoliaIndexName = $algoliaIndexName;
        $this->cacheDir = $cacheDir;
        parent::__construct();
    }

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

        $deployLock = $this->cacheDir.'/deploy.globallock';
        if (file_exists($deployLock)) {
            if ($verbose) {
                $output->writeln('Aborting, '.$deployLock.' file present');
            }
            return;
        }

        $lockAcquired = $this->locker->lockCommand($this->getName());
        if (!$lockAcquired) {
            if ($input->getOption('verbose')) {
                $output->writeln('Aborting, another task is running already');
            }
            return;
        }

        $index = $this->algolia->initIndex($this->algoliaIndexName);

        if ($package) {
            $packages = array(array('id' => $this->doctrine->getRepository(Package::class)->findOneByName($package)->getId()));
        } elseif ($force || $indexAll) {
            $packages = $this->doctrine->getManager()->getConnection()->fetchAll('SELECT id FROM package ORDER BY id ASC');
            $this->doctrine->getManager()->getConnection()->executeQuery('UPDATE package SET indexedAt = NULL');
        } else {
            $packages = $this->doctrine->getRepository(Package::class)->getStalePackagesForIndexing();
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

            $index->clear();
        }

        $total = count($ids);
        $current = 0;

        // update package index
        while ($ids) {
            $indexTime = new \DateTime;
            $idsSlice = array_splice($ids, 0, 50);
            $packages = $this->doctrine->getRepository(Package::class)->findById($idsSlice);

            $idsToUpdate = [];
            $records = [];

            foreach ($packages as $package) {
                $current++;
                if ($verbose) {
                    $output->writeln('['.sprintf('%'.strlen($total).'d', $current).'/'.$total.'] Indexing '.$package->getName());
                }

                // delete spam packages from the search index
                if ($package->isAbandoned() && $package->getReplacementPackage() === 'spam/spam') {
                    try {
                        $index->deleteObject($package->getName());
                        $idsToUpdate[] = $package->getId();
                        continue;
                    } catch (\Algolia\AlgoliaSearch\Exceptions\AlgoliaException $e) {
                    }
                }

                try {
                    $tags = $this->getTags($this->doctrine, $package);

                    $records[] = $this->packageToSearchableArray($package, $tags);

                    $idsToUpdate[] = $package->getId();
                } catch (\Exception $e) {
                    $output->writeln('<error>Exception: '.$e->getMessage().', skipping package '.$package->getName().'.</error>');

                    continue;
                }

                $providers = $this->getProviders($this->doctrine, $package);
                foreach ($providers as $provided) {
                    $records[] = $this->createSearchableProvider($provided['packageName']);
                }
            }

            try {
                $index->saveObjects($records);
            } catch (\Exception $e) {
                $output->writeln('<error>'.get_class($e).': '.$e->getMessage().', occurred while processing packages: '.implode(',', $idsSlice).'</error>');
                continue;
            }

            $this->doctrine->getManager()->clear();
            unset($packages);

            if ($verbose) {
                $output->writeln('Updating package indexedAt column');
            }

            $this->updateIndexedAt($idsToUpdate, $this->doctrine, $indexTime->format('Y-m-d H:i:s'));
        }

        $this->locker->unlockCommand($this->getName());
    }

    private function packageToSearchableArray(Package $package, array $tags)
    {
        $faversCount = $this->favoriteManager->getFaverCount($package);
        $downloads = $this->downloadManager->getDownloads($package);
        $downloadsLog = $downloads['monthly'] > 0 ? log($downloads['monthly'], 10) : 0;
        $starsLog = $package->getGitHubStars() > 0 ? log($package->getGitHubStars(), 10) : 0;
        $popularity = round($downloadsLog + $starsLog);
        $trendiness = $this->redis->zscore('downloads:trending', $package->getId());

        $record = [
            'id' => $package->getId(),
            'objectID' => $package->getName(),
            'name' => $package->getName(),
            'package_organisation' => $package->getVendor(),
            'package_name' => $package->getPackageName(),
            'description' => preg_replace('{[\x00-\x1f]+}u', '', strip_tags($package->getDescription())),
            'type' => $package->getType(),
            'repository' => $package->getRepository(),
            'language' => $package->getLanguage(),
            # log10 of downloads over the last 7days
            'trendiness' => $trendiness > 0 ? log($trendiness, 10) : 0,
            # log10 of downloads + gh stars
            'popularity' => $popularity,
            'meta' => [
                'downloads' => $downloads['total'],
                'downloads_formatted' => number_format($downloads['total'], 0, ',', ' '),
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

    private function createSearchableProvider(string $provided)
    {
        return [
            'id' => $provided,
            'objectID' => 'virtual:'.$provided,
            'name' => $provided,
            'package_organisation' => preg_replace('{/.*$}', '', $provided),
            'package_name' => preg_replace('{^[^/]*/}', '', $provided),
            'description' => '',
            'type' => 'virtual-package',
            'repository' => '',
            'language' => '',
            'trendiness' => 100,
            'popularity' => 4,
            'abandoned' => 0,
            'replacementPackage' => '',
            'tags' => [],
        ];
    }

    private function getProviders(ManagerRegistry $doctrine, Package $package): array
    {
        return $doctrine->getManager()->getConnection()->fetchAll(
            'SELECT lp.packageName
                FROM package p
                JOIN package_version pv ON p.id = pv.package_id
                JOIN link_provide lp ON lp.version_id = pv.id
                WHERE p.id = :id
                AND pv.development = true
                GROUP BY lp.packageName',
            ['id' => $package->getId()]
        );
    }

    private function getTags(ManagerRegistry $doctrine, Package $package): array
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

        return array_values(array_unique(array_map(function ($tag) {
            return preg_replace('{[\s-]+}u', ' ', mb_strtolower(preg_replace('{[\x00-\x1f]+}u', '', $tag), 'UTF-8'));
        }, $tags)));
    }

    private function updateIndexedAt(array $idsToUpdate, ManagerRegistry $doctrine, string $time)
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
