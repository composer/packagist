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
use Composer\Pcre\Preg;
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
    use \App\Util\DoctrineTrait;

    public function __construct(
        private SearchClient $algolia,
        private Locker $locker,
        private ManagerRegistry $doctrine,
        private Client $redis,
        private DownloadManager $downloadManager,
        private FavoriteManager $favoriteManager,
        private string $algoliaIndexName,
        private string $cacheDir,
        private \Graze\DogStatsD\Client $statsd,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:index')
            ->setDefinition([
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force a re-indexing of all packages'),
                new InputOption('all', null, InputOption::VALUE_NONE, 'Index all packages without clearing the index first'),
                new InputArgument('package', InputArgument::OPTIONAL, 'Package name to index'),
            ])
            ->setDescription('Indexes packages in Algolia')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
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
            return 0;
        }

        $lockAcquired = $this->locker->lockCommand(__CLASS__);
        if (!$lockAcquired) {
            if ($input->getOption('verbose')) {
                $output->writeln('Aborting, another task is running already');
            }
            return 0;
        }

        $index = $this->algolia->initIndex($this->algoliaIndexName);

        if ($package) {
            $packageEntity = $this->getEM()->getRepository(Package::class)->findOneBy(['name' => $package]);
            if ($packageEntity === null) {
                $output->writeln('<error>Package '.$package.' not found</error>');
                return 1;
            }
            $packages = [['id' => $packageEntity->getId()]];
        } elseif ($force || $indexAll) {
            $this->statsd->increment('nightly-job.start', 1, 1, ['job' => 'index-packages']);

            $packages = $this->getEM()->getConnection()->fetchAllAssociative('SELECT id FROM package ORDER BY id ASC');
            if ($force) {
                $this->getEM()->getConnection()->executeQuery('UPDATE package SET indexedAt = NULL');
            }
        } else {
            $packages = $this->getEM()->getRepository(Package::class)->getStalePackagesForIndexing();
        }

        $ids = [];
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
            $packages = $this->getEM()->getRepository(Package::class)->findBy(['id' => $idsSlice]);

            $idsToUpdate = [];
            $records = [];

            foreach ($packages as $package) {
                $current++;
                if ($verbose) {
                    $output->writeln('['.sprintf('%'.strlen((string)$total).'d', $current).'/'.$total.'] Indexing '.$package->getName());
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
                    $tags = $this->getTags($package);

                    $records[] = $this->packageToSearchableArray($package, $tags);

                    $idsToUpdate[] = $package->getId();
                } catch (\Exception $e) {
                    $output->writeln('<error>Exception: '.$e->getMessage().', skipping package '.$package->getName().'.</error>');

                    continue;
                }

                $providers = $this->getProviders($package);
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

            $this->getEM()->clear();
            unset($packages);

            if ($verbose) {
                $output->writeln('Updating package indexedAt column');
            }

            $this->updateIndexedAt($idsToUpdate, $indexTime->format('Y-m-d H:i:s'));
        }

        $this->locker->unlockCommand(__CLASS__);
        if ($force || $indexAll) {
            $this->statsd->increment('nightly-job.end', 1, 1, ['job' => 'index-packages']);
        }

        return 0;
    }

    /**
     * @param string[] $tags
     * @return array<string, int|string|float|null|array<string, string|int>>
     */
    private function packageToSearchableArray(Package $package, array $tags): array
    {
        $faversCount = $this->favoriteManager->getFaverCount($package);
        $downloads = $this->downloadManager->getDownloads($package);
        $downloadsLog = $downloads['monthly'] > 0 ? log($downloads['monthly'], 10) : 0;
        $starsLog = $package->getGitHubStars() > 0 ? log($package->getGitHubStars(), 10) : 0;
        $popularity = round($downloadsLog + $starsLog);
        $trendiness = (float)$this->redis->zscore('downloads:trending', $package->getId());

        $record = [
            'id' => $package->getId(),
            'objectID' => $package->getName(),
            'name' => $package->getName(),
            'package_organisation' => $package->getVendor(),
            'package_name' => $package->getPackageName(),
            'description' => Preg::replace('{[\x00-\x1f]+}u', '', strip_tags((string) $package->getDescription())),
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

    /**
     * @return array<string, string|int|array{}>
     */
    private function createSearchableProvider(string $provided): array
    {
        return [
            'id' => $provided,
            'objectID' => 'virtual:'.$provided,
            'name' => $provided,
            'package_organisation' => Preg::replace('{/.*$}', '', $provided),
            'package_name' => Preg::replace('{^[^/]*/}', '', $provided),
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

    /**
     * @return array<array{packageName: string}>
     */
    private function getProviders(Package $package): array
    {
        return $this->getEM()->getConnection()->fetchAllAssociative(
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

    /**
     * @return string[]
     */
    private function getTags(Package $package): array
    {
        $rows = $this->getEM()->getConnection()->fetchAllAssociative(
            'SELECT t.name FROM package p
                            JOIN package_version pv ON p.id = pv.package_id
                            JOIN version_tag vt ON vt.version_id = pv.id
                            JOIN tag t ON t.id = vt.tag_id
                            WHERE p.id = :id
                            GROUP BY t.id, t.name',
            ['id' => $package->getId()]
        );

        $tags = [];
        foreach ($rows as $tag) {
            $tags[] = $tag['name'];
        }

        return array_values(array_unique(array_map(
            fn (string $tag) => Preg::replace('{[\s-]+}u', ' ', mb_strtolower(Preg::replace('{[\x00-\x1f]+}u', '', $tag), 'UTF-8')),
            $tags
        )));
    }

    /**
     * @param int[] $idsToUpdate
     */
    private function updateIndexedAt(array $idsToUpdate, string $time): void
    {
        $retries = 5;
        // retry loop in case of a lock timeout
        while ($retries--) {
            try {
                // updating only if indexedAt is <crawledAt, to make sure the package is not stale for indexing anymore
                // but in the nightly job where we index all packages anyway, we do not need to update all of them
                $this->getEM()->getConnection()->executeQuery(
                    'UPDATE package SET indexedAt=:indexed WHERE id IN (:ids) AND (indexedAt IS NULL OR indexedAt <= crawledAt)',
                    [
                        'ids' => $idsToUpdate,
                        'indexed' => $time,
                    ],
                    ['ids' => Connection::PARAM_INT_ARRAY]
                );

                // make sure that packages where crawledAt is set in far future do not get indexed repeatedly
                $this->getEM()->getConnection()->executeQuery(
                    'UPDATE package SET indexedAt=DATE_ADD(crawledAt, INTERVAL 1 SECOND) WHERE id IN (:ids) AND indexedAt <= crawledAt AND crawledAt > :tomorrow',
                    [
                        'ids' => $idsToUpdate,
                        'tomorrow' => date('Y-m-d H:i:s', strtotime('+1day')),
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
