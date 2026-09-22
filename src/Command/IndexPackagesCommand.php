<?php declare(strict_types=1);

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

use App\Entity\Package;
use App\Entity\Version;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;
use App\Search\PackageIndex;
use App\Service\Locker;
use Composer\Pcre\Preg;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @phpstan-import-type PackageRecord from PackageIndex
 */
class IndexPackagesCommand extends Command
{
    use \App\Util\DoctrineTrait;

    public function __construct(
        private PackageIndex $packageIndex,
        private Locker $locker,
        private ManagerRegistry $doctrine,
        private Client $redis,
        private DownloadManager $downloadManager,
        private FavoriteManager $favoriteManager,
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
                new InputOption('all', null, InputOption::VALUE_NONE, 'Index all packages without clearing the index first'),
                new InputArgument('package', InputArgument::OPTIONAL, 'Package name to index'),
            ])
            ->setDescription('Indexes packages in Algolia')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = $input->getOption('verbose');
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

        if ($package) {
            $packageEntity = $this->getEM()->getRepository(Package::class)->findOneBy(['name' => $package]);
            if ($packageEntity === null) {
                $output->writeln('<error>Package '.$package.' not found</error>');

                return 1;
            }
            $packages = [['id' => $packageEntity->getId()]];
        } elseif ($indexAll) {
            $this->statsd->increment('nightly_job.start', 1, 1, ['job' => 'index-packages']);

            $packages = $this->getEM()->getConnection()->fetchAllAssociative('SELECT id FROM package ORDER BY id ASC');
        } else {
            $packages = $this->getEM()->getRepository(Package::class)->getStalePackagesForIndexing();
        }

        $ids = [];
        foreach ($packages as $row) {
            $ids[] = $row['id'];
        }

        $total = \count($ids);
        $current = 0;

        // update package index
        while ($ids) {
            $indexTime = new \DateTime();
            $idsSlice = array_splice($ids, 0, 50);
            $packages = $this->getEM()->getRepository(Package::class)->findBy(['id' => $idsSlice]);
            $tagsById = $this->getTags($idsSlice);
            $providersById = $this->getProviders($idsSlice);

            $idsToUpdate = [];
            $records = [];

            foreach ($packages as $package) {
                $current++;
                if ($verbose) {
                    $output->writeln('['.\sprintf('%'.\strlen((string) $total).'d', $current).'/'.$total.'] Indexing '.$package->getName());
                }

                // delete suppressed (spam/malware) packages from the search index
                if ($package->isFrozen() && $package->getFreezeReason()?->suppressesPackage()) {
                    try {
                        $this->packageIndex->deleteRecord($package->getName());
                        $idsToUpdate[] = $package->getId();
                        continue;
                    } catch (\Algolia\AlgoliaSearch\Exceptions\AlgoliaException|\InvalidArgumentException $e) {
                    }
                }

                try {
                    $records[] = $this->packageToSearchableArray($package, $tagsById[$package->getId()] ?? []);

                    $idsToUpdate[] = $package->getId();
                } catch (\Exception $e) {
                    $output->writeln('<error>Exception: '.$e->getMessage().', skipping package '.$package->getName().'.</error>');

                    continue;
                }

                foreach ($providersById[$package->getId()] ?? [] as $provided) {
                    $records[] = $this->createSearchableProvider($provided);
                }
            }

            try {
                $this->packageIndex->saveRecords($records);
            } catch (\Exception $e) {
                $output->writeln('<error>'.$e::class.': '.$e->getMessage().', occurred while processing packages: '.implode(',', $idsSlice).'</error>');
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
        if ($indexAll) {
            $this->statsd->increment('nightly_job.end', 1, 1, ['job' => 'index-packages']);
        }

        return 0;
    }

    /**
     * @param list<string> $tags
     *
     * @phpstan-return PackageRecord
     */
    private function packageToSearchableArray(Package $package, array $tags): array
    {
        $faversCount = $this->favoriteManager->getFaverCount($package);
        $downloads = $this->downloadManager->getDownloads($package);
        $downloadsLog = $downloads['monthly'] > 0 ? log($downloads['monthly'], 10) : 0;
        $starsLog = $package->getGitHubStars() > 0 ? log($package->getGitHubStars(), 10) : 0;
        $popularity = round($downloadsLog + $starsLog);
        $trendiness = (float) $this->redis->zscore('downloads:trending', (string) $package->getId());

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
            // log10 of downloads over the last 7days
            'trendiness' => $trendiness > 0 ? log($trendiness, 10) : 0,
            // log10 of downloads + gh stars
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

        if ($package->isPiePackage()) {
            $record['extension'] = 1;
            $latestVersion = $this->getEM()->getRepository(Version::class)->getQueryBuilderForLatestVersionWithPackage(package: $package->getName())
                ->getQuery()->setMaxResults(1)->getOneOrNullResult();
            if ($latestVersion) {
                $record['extensionName'] = $latestVersion->getPieName();
            }
        }

        $record['tags'] = $tags;

        return $record;
    }

    /**
     * @phpstan-return PackageRecord
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
     * Virtual packages provided by each package's default branch, keyed by package id.
     *
     * Per slice rather than per package: this ran 74,912,681 times for 104,677s of DB time in the
     * prod digests, one execution per package per pass, where 50 packages share one query.
     *
     * @param list<int> $ids
     *
     * @return array<int, list<string>>
     */
    private function getProviders(array $ids): array
    {
        $rows = $this->getEM()->getConnection()->fetchAllAssociative(
            'SELECT pv.package_id AS packageId, lp.packageName AS packageName
                FROM package_version pv
                JOIN link_provide lp ON lp.version_id = pv.id
                WHERE pv.package_id IN (:ids)
                AND pv.development = true
                GROUP BY pv.package_id, lp.packageName',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $providersById = [];
        foreach ($rows as $row) {
            $providersById[(int) $row['packageId']][] = (string) $row['packageName'];
        }

        return $providersById;
    }

    /**
     * Normalized tags across all of each package's versions, keyed by package id. Batched per slice
     * for the same reason as {@see getProviders()}.
     *
     * @param list<int> $ids
     *
     * @return array<int, list<string>>
     */
    private function getTags(array $ids): array
    {
        $rows = $this->getEM()->getConnection()->fetchAllAssociative(
            'SELECT pv.package_id AS packageId, t.name AS name
                FROM package_version pv
                JOIN version_tag vt ON vt.version_id = pv.id
                JOIN tag t ON t.id = vt.tag_id
                WHERE pv.package_id IN (:ids)
                GROUP BY pv.package_id, t.id, t.name',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $tagsById = [];
        foreach ($rows as $row) {
            $tagsById[(int) $row['packageId']][] = Preg::replace('{[\s-]+}u', ' ', mb_strtolower(Preg::replace('{[\x00-\x1f]+}u', '', (string) $row['name']), 'UTF-8'));
        }

        // deduped after normalizing, so tags differing only in case or spacing collapse together
        return array_map(static fn (array $tags): array => array_values(array_unique($tags)), $tagsById);
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
                    ['ids' => ArrayParameterType::INTEGER]
                );

                // make sure that packages where crawledAt is set in far future do not get indexed repeatedly
                $this->getEM()->getConnection()->executeQuery(
                    'UPDATE package SET indexedAt=DATE_ADD(crawledAt, INTERVAL 1 SECOND) WHERE id IN (:ids) AND indexedAt <= crawledAt AND crawledAt > :tomorrow',
                    [
                        'ids' => $idsToUpdate,
                        'tomorrow' => date('Y-m-d H:i:s', strtotime('+1day')),
                    ],
                    ['ids' => ArrayParameterType::INTEGER]
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
