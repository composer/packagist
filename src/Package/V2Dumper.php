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

namespace App\Package;

use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\SecurityAdvisory;
use App\Entity\Version;
use App\FilterList\Dump\DumpableFilterList;
use App\FilterList\Dump\FilterListDumperProvider;
use App\FilterList\Dump\FilterListSummaryDumper;
use App\FilterList\FilterLists;
use App\Log\AuditLogEventType;
use App\Model\ProviderManager;
use App\Service\CdnClient;
use App\Service\ReplicaClient;
use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Pcre\Preg;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Graze\DogStatsD\Client as StatsDClient;
use Monolog\Logger;
use Predis\Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;
use Webmozart\Assert\Assert;

/**
 * v2 Metadata Dumper
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class V2Dumper
{
    use \App\Util\DoctrineTrait;

    private const CDN_PURGE_SETTLE_SECONDS = 2;

    /** @var list<string> */
    private array $writtenFiles = [];

    private bool $bypassIndividualPurges = false;

    /** Whether the current run dumps everything regardless of staleness, i.e. packagist:dump-v2 --force. */
    private bool $forcedRun = false;

    // instrumentation only: the current run's worker id, used to tag statsd metrics emitted from writeV2File
    private int $workerId = 0;

    public function __construct(
        private ManagerRegistry $doctrine,
        private Filesystem $filesystem,
        private Client $redis,
        private string $webDir,
        private string $buildDir,
        private StatsDClient $statsd,
        private ProviderManager $providerManager,
        private Logger $logger,
        private readonly CdnClient $cdnClient,
        private readonly ReplicaClient $replicaClient,
        private readonly UrlGeneratorInterface $router,
        #[Autowire(service: 'http_client')]
        private readonly HttpClientInterface&ResetInterface $httpClient,
        private readonly FilterListDumperProvider $filterListDumperProvider,
    ) {
        $webDir = realpath($webDir);
        Assert::string($webDir);
        $this->webDir = $webDir;
    }

    public function dumpRoot(bool $verbose = false): void
    {
        // prepare root file
        $rootFile = $this->webDir.'/packages.json';
        $rootFileContents = [
            'packages' => [],
        ];

        $rootFileContents['notify-batch'] = $this->router->generate('track_download_batch', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $rootFileContents['providers-url'] = $this->router->generate('home', []).'p/%package%$%hash%.json';
        $rootFileContents['metadata-url'] = str_replace('https://', 'https://repo.', $this->router->generate('home', [], UrlGeneratorInterface::ABSOLUTE_URL)).'p2/%package%.json';
        $rootFileContents['metadata-changes-url'] = $this->router->generate('metadata_changes', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $rootFileContents['search'] = $this->router->generate('search_api', [], UrlGeneratorInterface::ABSOLUTE_URL).'?q=%query%&type=%type%';
        $rootFileContents['list'] = $this->router->generate('list', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $rootFileContents['security-advisories'] = [
            'metadata' => true, // whether advisories are part of the metadata v2 files
            'api-url' => $this->router->generate('api_security_advisories', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
        $rootFileContents['providers-api'] = str_replace('VND/PKG', '%package%', $this->router->generate('view_providers', ['name' => 'VND/PKG', '_format' => 'json'], UrlGeneratorInterface::ABSOLUTE_URL));
        $rootFileContents['warning'] = 'Support for Composer 1 has been shutdown on September 1st 2025. You should upgrade to Composer 2. See https://blog.packagist.com/shutting-down-packagist-org-support-for-composer-1-x/';
        $rootFileContents['warning-versions'] = '<1.999';
        $rootFileContents['providers'] = [];
        $rootFileContents['filter'] = [
            'metadata' => true,
            'lists' => FilterLists::packagesJsonListConfig(),
            'summary-url' => str_replace('https://', 'https://repo.', $this->router->generate('home', [], UrlGeneratorInterface::ABSOLUTE_URL)) . FilterListSummaryDumper::SUMMARY_PATH,
        ];

        if ($verbose) {
            echo 'Dumping root'.\PHP_EOL;
        }
        $this->dumpRootFile($rootFile, json_encode($rootFileContents, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        $this->statsd->increment('packagist.metadata_dump_root');
    }

    /**
     * Dump a set of packages to the web root
     *
     * @param list<int> $packageIds
     */
    public function dump(array $packageIds, bool $force = false, bool $verbose = false, int $workerId = 0): V2DumpResult
    {
        // clear written files tracking for this run
        $this->writtenFiles = [];

        // instrumentation only: track where the wall-clock of a run goes and how much of it is full (forced) dumps
        $this->workerId = $workerId;
        $runStart = microtime(true);
        $processedPackages = 0;
        $forceDumpPackages = 0;

        // prepare build dir
        $webDir = $this->webDir;

        $buildDirV2 = $this->buildDir.'/p2';

        // initialize
        $initialRun = false;
        if (!is_dir($buildDirV2)) {
            $initialRun = true;
            $this->filesystem->mkdir($buildDirV2);
        }
        $buildDirV2 = realpath($buildDirV2);
        Assert::string($buildDirV2);

        // copy existing stuff for smooth BC transition
        if ($initialRun && !$force) {
            throw new \RuntimeException('Run this again with --force the first time around to make sure it dumps all packages');
        }

        $this->forcedRun = $force;

        if ($verbose) {
            echo 'Web dir is '.$webDir.'/p2 ('.realpath($webDir.'/p2').')'.\PHP_EOL;
            echo 'Build v2 dir is '.$buildDirV2.\PHP_EOL;
        }

        $selectedIds = $packageIds;
        $dumpTimeUpdates = [];
        $outcome = V2DumpOutcome::Complete;

        $versionRepo = $this->getEM()->getRepository(Version::class);

        $total = \count($packageIds);
        $current = 0;
        $step = 50;

        if (\count($packageIds) > 150) {
            $this->bypassIndividualPurges = true;
            $this->logger->warning('Bypassing CDN cache purges due to elevated queue size', ['queue' => \count($packageIds)]);
        }

        while ($packageIds) {
            $this->statsd->gauge('packagist.metadata_dump_queue', \count($packageIds), ['worker' => (string) $workerId]);

            // Captured before the hydration below, and load-bearing: a markForDump() landing after this
            // point leaves dumpRequestedAt >= the dumpedAtV2 we are about to record, so the package
            // stays stale and is picked up next run. Move it below the hydration and those marks are
            // silently lost.
            $dumpTime = new \DateTime();
            $idBatch = array_splice($packageIds, 0, $step);
            $this->logger->debug('Dumping package ids', ['ids' => $idBatch]);
            $hydrateStart = microtime(true);
            $packages = $this->getEM()->getRepository(Package::class)->getPackagesWithVersions($idBatch);
            $this->statsd->timing('packagist.metadata_dump.db_time', round((microtime(true) - $hydrateStart) * 1000, 4), ['op' => 'hydrate', 'worker' => (string) $workerId]);
            unset($idBatch);
            $packageNames = array_map(static fn (Package $pkg) => $pkg->getName(), $packages);
            $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->getAdvisoryIdsAndVersions($packageNames);
            $filterLists = $this->filterListDumperProvider->getEntriesForDump($packageNames);

            if ($verbose) {
                echo '['.\sprintf('%'.\strlen((string) $total).'d', $current).'/'.$total.'] Processing '.$step.' packages'.\PHP_EOL;
            }

            $current += $step;

            // prepare packages in memory
            foreach ($packages as $package) {
                // skip suppressed (spam/malware) packages in the dumper in case one appears due to a race condition
                if ($package->isFrozen() && $package->getFreezeReason()?->suppressesPackage()) {
                    continue;
                }

                // write versions in individual files
                $versionIds = [];
                foreach ($package->getVersions() as $version) {
                    $versionIds[] = $version->getId();
                }
                $versionDataStart = microtime(true);
                $versionData = $versionRepo->getVersionData($versionIds);
                $this->statsd->timing('packagist.metadata_dump.db_time', round((microtime(true) - $versionDataStart) * 1000, 4), ['op' => 'versiondata', 'worker' => (string) $workerId]);

                // instrumentation only: count processed packages and how many need a full (forced) dump vs. a cheap
                // early-return. isDumpForced() is "dumpedAtV2 IS NULL", which never-dumped packages satisfy too, so
                // read run_forcedump as "full writes", not "forceDump() calls".
                $processedPackages++;
                if ($package->isDumpForced()) {
                    $forceDumpPackages++;
                }

                // dump v2 format; a failed package is left out of $dumpTimeUpdates so it stays stale
                try {
                    $this->dumpPackageToV2File($buildDirV2, $package, $versionData, $advisories[$package->getName()] ?? [], $filterLists[$package->getName()] ?? null);
                } catch (TransportExceptionInterface $e) {
                    // the next package would wait out the same timeouts, so stop here but keep what was dumped
                    $this->logger->warning('CDN unreachable, aborting v2 metadata dump run', ['package' => $package->getName(), 'exception' => $e, 'worker' => $workerId]);
                    $outcome = V2DumpOutcome::Aborted;
                    break 2;
                } catch (\Throwable $e) {
                    $this->logger->error('Failed dumping v2 metadata, leaving the package stale', ['package' => $package->getName(), 'exception' => $e, 'worker' => $workerId]);
                    $outcome = V2DumpOutcome::SomeFailed;
                    continue;
                }

                $dumpTimeUpdates[$dumpTime->format('Y-m-d H:i:s')][] = $package->getId();
            }

            unset($packages, $package, $version, $advisories, $packageNames);
            $this->getEM()->clear();
            $this->logger->reset();
        }

        // Verify all written files match CDN versions
        if ($outcome !== V2DumpOutcome::Aborted && !$this->bypassIndividualPurges && !empty($this->writtenFiles) && !$force && \count($this->writtenFiles) < 50) {
            if ($verbose) {
                echo 'Verifying '.\count($this->writtenFiles).' written files match CDN versions'.\PHP_EOL;
            }
            $this->verifyCdnFiles();
        }

        // purge complete cache at once if we have a backed up queue, otherwise the CDN purges tend to slow down clearing the queue
        // due to CDN purges rate limiting
        if ($this->bypassIndividualPurges) {
            $this->bypassIndividualPurges = false;
            if ($this->cdnClient->isConfigured()) {
                $this->logger->warning('Purging complete CDN metadata cache after dump is complete');
                try {
                    $this->cdnClient->purgeMetadataCache('p2/*');
                } catch (\Throwable $e) {
                    $this->logger->error('Failed purging the complete CDN metadata cache', ['exception' => $e, 'worker' => $workerId]);
                }
            }
        }

        if (!file_exists($webDir.'/p2') && !@symlink($buildDirV2, $webDir.'/p2')) {
            echo 'Warning: Could not symlink the build dir v2 into the web dir';
            throw new \RuntimeException('Could not symlink the build dir v2 into the web dir');
        }

        if ($verbose) {
            echo 'Updating package dump times'.\PHP_EOL;
        }

        $dbUpdateStart = microtime(true);
        foreach ($dumpTimeUpdates as $dt => $ids) {
            $retries = 5;
            // retry loop in case of a lock timeout
            while ($retries--) {
                try {
                    $this->getEM()->getConnection()->executeQuery(
                        'UPDATE package SET dumpedAtV2=:dumped WHERE id IN (:ids)',
                        [
                            'ids' => $ids,
                            'dumped' => $dt,
                        ],
                        ['ids' => ArrayParameterType::INTEGER]
                    );
                    break;
                } catch (\Exception $e) {
                    if (!$retries) {
                        throw $e;
                    }
                    // instrumentation only: count lock-timeout retries to detect package-table write contention
                    $this->statsd->increment('packagist.metadata_dump.dbupdate_retry', 1, 1, ['worker' => (string) $workerId]);
                    sleep(2);
                }
            }
        }

        // instrumentation only: time the dumpedAtV2 UPDATE and the overall run, plus how many packages were
        // processed and how many needed a full (forced) dump — overlay these on packagist.metadata_dump_queue
        $this->statsd->timing('packagist.metadata_dump.dbupdate_time', round((microtime(true) - $dbUpdateStart) * 1000, 4), ['worker' => (string) $workerId]);
        $this->statsd->timing('packagist.metadata_dump.run_time', round((microtime(true) - $runStart) * 1000, 4), ['worker' => (string) $workerId]);
        $this->statsd->histogram('packagist.metadata_dump.run_packages', $processedPackages, 1.0, ['worker' => (string) $workerId]);
        $this->statsd->histogram('packagist.metadata_dump.run_forcedump', $forceDumpPackages, 1.0, ['worker' => (string) $workerId]);

        return new V2DumpResult($outcome, array_values(array_diff($selectedIds, array_merge(...array_values($dumpTimeUpdates)))));
    }

    public function gc(): void
    {
        $finder = Finder::create()->directories()->ignoreVCS(true)->in($this->buildDir.'/p2');
        $packageNames = array_flip($this->providerManager->getPackageNames());

        foreach ($finder as $vendorDir) {
            $files = glob(((string) $vendorDir).'/*.json');
            Assert::isArray($files);
            foreach ($files as $file) {
                if (!Preg::isMatch('{/([^/]+/[^/]+?)(~dev)?\.json$}', strtr($file, '\\', '/'), $match)) {
                    throw new \LogicException('Could not match package name from '.$file);
                }

                if (!isset($packageNames[$match[1]])) {
                    unlink((string) $file);
                }
            }
        }

        $time = (time() - 86400) * 10000;
        $this->redis->set('metadata-oldest', $time);
        $this->redis->zremrangebyscore('metadata-dumps', 0, $time - 1);
        $this->redis->zremrangebyscore('metadata-deletes', 0, $time - 1);
    }

    private function dumpRootFile(string $file, string $json): void
    {
        if (file_exists($file) && file_get_contents($file) === $json) {
            return;
        }

        $filemtime = $this->writeCdn('packages.json', $json);

        $this->writeFileAtomic($file, $json, $filemtime);
        $encoded = gzencode($json, 8);
        \assert(\is_string($encoded));
        $this->writeFileAtomic($file.'.gz', $encoded, $filemtime);

        $this->purgeCdn('packages.json');
    }

    private function writeFileAtomic(string $path, string $contents, ?int $mtime = null): void
    {
        file_put_contents($path.'.tmp', $contents);
        if ($mtime !== null) {
            touch($path.'.tmp', $mtime);
        }
        rename($path.'.tmp', $path);
    }

    /**
     * @param mixed[]                                                    $versionData
     * @param array<array{advisoryId: string, affectedVersions: string}> $advisories
     * @param array<string, list<DumpableFilterList>>|null               $filterLists
     */
    private function dumpPackageToV2File(string $dir, Package $package, array $versionData, array $advisories, ?array $filterLists): void
    {
        $name = strtolower($package->getName());

        // Soft-deleted versions stay in the DB so the UI can list them grayed out, but they must not
        // appear in metadata — Composer should not be able to resolve a pulled version.
        $versions = array_filter(
            $package->getVersions()->toArray(),
            static fn (Version $v): bool => !$v->isSoftDeleted()
        );
        usort($versions, Package::sortVersions(...));

        $tags = [];
        $branches = [];
        foreach ($versions as $version) {
            if ($version->isDevelopment()) {
                $branches[] = $version;
            } else {
                $tags[] = $version;
            }
        }

        $this->dumpVersionsToV2File($package, $name, $dir, $name.'.json', $name, $tags, $versionData, $advisories, $filterLists);
        $this->dumpVersionsToV2File($package, $name, $dir, $name.'~dev.json', $name, $branches, $versionData);
    }

    /**
     * @param array<array{advisoryId: string, affectedVersions: string}>|null $advisories
     * @param array<string, list<DumpableFilterList>>|null                    $filterLists
     * @param array<Version>                                                  $versions
     * @param VersionData                                                     $versionData
     */
    private function dumpVersionsToV2File(Package $package, string $name, string $dir, string $filename, string $packageName, array $versions, array $versionData, ?array $advisories = null, ?array $filterLists = null): void
    {
        $versionArrays = [];
        foreach ($versions as $version) {
            $versionArrays[] = $version->toV2Array($versionData);
        }

        $path = $dir.'/'.$filename;

        $metadata = [
            'minified' => 'composer/2.0',
            'packages' => [
                $packageName => MetadataMinifier::minify($versionArrays),
            ],
        ];

        if ($advisories !== null) {
            $metadata['security-advisories'] = $advisories;
        }

        if ($filterLists !== null) {
            $metadata['filter'] = $filterLists;
        }

        $json = json_encode($metadata, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $this->writeV2File($package, $name, $path, $json);
    }

    private function writeV2File(Package $package, string $name, string $path, string $contents): void
    {
        // instrumentation only: time the whole per-file cost and each network op, and classify the outcome
        // (skipped early-return / deleted-guard / full written) so we can see where the dump loop spends time
        $fileStart = microtime(true);
        $result = 'written';
        $opTimings = [];
        $workerTag = ['worker' => (string) $this->workerId];

        try {
            // ensure we do not upload files to the cdn for packages that have been recently deleted to avoid race conditions
            $deletion = $this->redis->zscore('metadata-deletes', $name);
            if ($deletion !== null && $this->doctrine->getRepository(AuditRecord::class)->findOneBy(['packageId' => $package->getId(), 'type' => AuditLogEventType::PackageDeleted]) !== null) {
                $this->logger->error('Skipped dumping a file as it is marked as having been deleted in the last 30seconds', ['file' => $path, 'deletion' => $deletion, 'time' => time()]);

                $result = 'deleted';

                return;
            }

            if (!Preg::isMatch('{/([^/]+/[^/]+?(~dev)?)\.json$}', $path, $match)) {
                throw new \LogicException('Could not match package name from '.$path);
            }

            $pkgWithDevFlag = $match[1];
            $relativePath = 'p2/'.$pkgWithDevFlag.'.json';

            // Always track files for CDN verification, even if they don't need updating
            // so that stuff gets purged in case it is outdated on the CDN still for some reason
            $this->writtenFiles[] = $relativePath;

            $fileExists = file_exists($path);
            if (!$fileExists) {
                // instrumentation only: distinguish a first write from a re-write, so a missing local file
                // does not land in the result:written bucket that gates dropping the crawledAt clause
                $result = 'created';
            } elseif (!$package->isDumpForced() && file_get_contents($path) === $contents) {
                // Deliberately isDumpForced(), not isDumpRequested(): every package the stale query hands
                // us is "requested", so honouring that would make this comparison dead code and turn
                // every dump into a real CDN write.
                $result = 'skipped';

                return;
            }

            // fetch the file to ensure the region we hit after has a hot cache so we can verify correctly that the purge worked
            if ($fileExists) {
                $this->cdnOp('prefetch', $opTimings, function () use ($relativePath): void {
                    try {
                        $this->cdnClient->fetchPublicMetadata($relativePath);
                    } catch (\Exception $e) {
                        // ignore 404s as if the file is not there yet it means it's unlikely to be cached in a bad state on some other region
                        if ($e->getCode() !== 404) {
                            throw $e;
                        }
                        $this->httpClient->reset();
                    }
                });
            }

            $this->filesystem->mkdir(\dirname($path));

            $filemtime = $this->cdnOp('upload', $opTimings, fn (): int => $this->writeCdn($relativePath, $contents));

            // we need to make sure dumps happen always with incrementing times to avoid race conditions when
            // fetching metadata changes in the currently elapsing second (new items dumped after the fetch can then
            // be skipped as they appear to have been dumped before the "since" param)
            // so this ensures a sequence even when we cannot rely on sub-second timing info in the filemtime
            if (str_ends_with((string) $filemtime, '0000')) {
                $counterKey = 'metadata:'.substr((string) $filemtime, 0, -4);
                $counter = $this->redis->incrby($counterKey, 10);
                if ($counter === 10) {
                    $this->redis->expire($counterKey, 10);
                }
                // safe-guard to avoid going beyond the current second in the very unlikely
                // case we'd dump more than 1000 packages in one second
                if ($counter > 9950) {
                    sleep(1);
                }
                $filemtime += $counter;
            }

            $timeUnix = (int) ceil($filemtime / 10000);
            $this->writeFileAtomic($path, $contents, $timeUnix);

            $this->cdnOp('replica', $opTimings, fn () => $this->writeToReplica($relativePath, $contents, $timeUnix));

            if (!$this->bypassIndividualPurges) {
                $this->cdnOp('purge', $opTimings, fn () => $this->purgeCdn($relativePath));
            }

            $this->redis->zadd('metadata-dumps', [$pkgWithDevFlag => $filemtime]);
            $this->statsd->increment('packagist.metadata_dump_v2');
        } catch (\Throwable $e) {
            // $result is set optimistically before the upload/replica/purge run, so without this a write
            // that threw would count as a successful one in the gate below
            $result = 'failed';

            throw $e;
        } finally {
            // instrumentation only: per-file duration + outcome counter, and a warning log for the slow outliers
            $fileMs = round((microtime(true) - $fileStart) * 1000, 4);
            // Two signals, keyed on whether the package was marked or merely swept up by the crawledAt
            // clause:
            //  - {written, requested:false} is the gap-detector: a change path that failed to mark. Must
            //    sit at ~0 before the crawledAt clause can be dropped (--force runs land here too).
            //  - {skipped, requested:true} is the inverse, a path marking more eagerly than it needs to.
            //    The >= rule gives this a permanent noise floor, so watch it for a step change.
            $gapTags = ['requested' => $package->isDumpRequested() ? 'true' : 'false'];
            $this->statsd->timing('packagist.metadata_dump.file_time', $fileMs, ['result' => $result] + $workerTag);
            $this->statsd->increment('packagist.metadata_dump.file', 1, 1, ['result' => $result] + $gapTags + $workerTag);
            if (($result === 'written' || $result === 'created') && $fileMs > 2000) {
                $this->logger->warning('Slow v2 metadata file dump', ['file' => $path, 'duration_ms' => $fileMs, 'ops_ms' => $opTimings, 'worker' => $this->workerId]);
            }
            // Names the packages behind the {written, requested:false} counter, i.e. content that changed
            // without any path marking it — the gap that keeps the crawledAt clause alive. Self-limiting:
            // the write updates dumpedAtV2, so a package can only reappear on its next real change.
            if ($result === 'written' && !$this->forcedRun && !$package->isDumpRequested()) {
                $this->logger->warning('Dumped a changed v2 file for a package nothing had marked', [
                    'package' => $package->getName(),
                    'dumpedAtV2' => $package->getDumpedAtV2()?->format('c'),
                    'dumpRequestedAt' => $package->getDumpRequestedAt()?->format('c'),
                    'crawledAt' => $package->getCrawledAt()?->format('c'),
                    'worker' => $this->workerId,
                ]);
            }
        }
    }

    /**
     * Times one CDN/replica op and counts its failures. cdn_failure is what tells a CDN outage
     * apart from a backlog we have to act on, so it is tagged by op and by how the op failed.
     *
     * @template T
     *
     * @param 'prefetch'|'upload'|'replica'|'purge' $op
     * @param array<string, float>                  $opTimings
     * @param callable(): T                         $fn
     *
     * @return T
     */
    private function cdnOp(string $op, array &$opTimings, callable $fn): mixed
    {
        $tags = ['op' => $op, 'worker' => (string) $this->workerId];
        $opStart = microtime(true);
        try {
            return $fn();
        } catch (\Throwable $e) {
            $reason = match (true) {
                $e instanceof TimeoutExceptionInterface => 'timeout',
                $e instanceof TransportExceptionInterface => 'transport',
                default => 'http',
            };
            $this->statsd->increment('packagist.metadata_dump.cdn_failure', 1, 1, $tags + ['reason' => $reason]);

            throw $e;
        } finally {
            $opTimings[$op] = round((microtime(true) - $opStart) * 1000, 4);
            $this->statsd->timing('packagist.metadata_dump.op_time', $opTimings[$op], $tags);
        }
    }

    /**
     * @param non-empty-string $relativePath
     *
     * @throws TransportExceptionInterface
     */
    private function writeCdn(string $relativePath, string $contents): int
    {
        try {
            return $this->cdnClient->uploadMetadata($relativePath, $contents);
        } catch (TransportExceptionInterface $e) {
            // the http client already retried this, so only try once more on a fresh connection pool
            $this->logger->debug('Retrying due to failure', ['exception' => $e]);
            sleep(1);
            $this->httpClient->reset();
        }

        return $this->cdnClient->uploadMetadata($relativePath, $contents);
    }

    private function writeToReplica(string $relativePath, string $contents, int $timeUnix): void
    {
        $retries = 3;
        do {
            try {
                $this->replicaClient->uploadMetadata($relativePath, $contents, $timeUnix);
                break;
            } catch (TransportExceptionInterface $e) {
                if ($retries === 0) {
                    $this->logger->error('Failed writing to replica after 3 attempts', ['file' => $relativePath, 'exception' => $e]);
                    throw $e;
                }
                $this->logger->debug('Retrying due to failure', ['file' => $relativePath, 'exception' => $e]);
                sleep(1);
                $this->httpClient->reset();
            }
        } while ($retries-- > 0);
    }

    private function purgeCdn(string $relativePath): void
    {
        $retries = 3;
        do {
            if ($this->cdnClient->purgeMetadataCache($relativePath)) {
                break;
            }
            if ($retries === 0) {
                throw new \RuntimeException('Failed to purge cache for '.$relativePath);
            }
            sleep(1);
            $this->httpClient->reset();
        } while ($retries-- > 0);
    }

    /**
     * Verify written files match their CDN counterparts
     */
    private function verifyCdnFiles(): void
    {
        if (!$this->cdnClient->isConfigured()) {
            return;
        }

        $this->logger->debug('Verifying CDN files', ['files' => $this->writtenFiles]);

        $repurged = [];
        foreach ($this->writtenFiles as $index => $relativePath) {
            $localPath = $this->webDir.'/'.$relativePath;
            if (!file_exists($localPath)) {
                continue; // Skip if local file doesn't exist
            }

            $localContent = (string) file_get_contents($localPath);
            try {
                $cdnContent = $this->fetchCdnFile($relativePath);
                if ($localContent === $cdnContent) {
                    $this->statsd->increment('packagist.metadata_cdn_purge_ok');
                    continue;
                }

                $this->statsd->increment('packagist.metadata_cdn_purge_warn');
                $this->logger->notice(
                    'Mismatch detected for CDN file: '.$relativePath.', purging again',
                    ['file' => $relativePath, 'local' => base64_encode($localContent), 'cdn' => base64_encode($cdnContent)]
                );
                $this->purgeCdn($relativePath);
                $repurged[$relativePath] = $localContent;
            } catch (\Throwable $e) {
                if (!$this->handleVerifyFailure($relativePath, \count($this->writtenFiles) - $index, $e)) {
                    return;
                }
            }
        }

        if (!$repurged) {
            return;
        }

        // the CDN acks a purge before it has processed it, so re-checking right away mostly reads the stale file
        sleep(self::CDN_PURGE_SETTLE_SECONDS);

        $remaining = \count($repurged);
        foreach ($repurged as $relativePath => $localContent) {
            try {
                $cdnContent = $this->fetchCdnFile($relativePath);
                if ($localContent !== $cdnContent) {
                    $this->statsd->increment('packagist.metadata_cdn_purge_fail');
                    $this->logger->error(
                        'Mismatch still present after another cache purge: '.$relativePath,
                        ['file' => $relativePath, 'local' => base64_encode($localContent), 'cdn' => base64_encode($cdnContent)]
                    );
                } else {
                    $this->logger->info('Cache purge worked now for '.$relativePath);
                }
            } catch (\Throwable $e) {
                if (!$this->handleVerifyFailure($relativePath, $remaining, $e)) {
                    return;
                }
            }
            $remaining--;
        }
    }

    /**
     * @return bool whether verifying the remaining files is worth continuing
     */
    private function handleVerifyFailure(string $relativePath, int $unverified, \Throwable $e): bool
    {
        if ($e instanceof TransportExceptionInterface) {
            // the next files would wait out the same timeouts
            $this->logger->warning('CDN unreachable, skipping verification of the remaining files', ['file' => $relativePath, 'unverified' => $unverified, 'exception' => $e]);

            return false;
        }

        $this->statsd->increment('packagist.metadata_cdn_purge_fail');
        $this->logger->warning('Failed verifying CDN file: '.$relativePath, ['file' => $relativePath, 'exception' => $e]);

        return true;
    }

    /**
     * Fetch file content from CDN as a regular client for verification
     */
    private function fetchCdnFile(string $relativePath): string
    {
        $retries = 3;
        do {
            try {
                return $this->cdnClient->fetchPublicMetadata($relativePath);
            } catch (\Exception $e) {
                if ($e->getCode() === 404) {
                    $this->logger->debug('File not found on CDN, retrying...', ['file' => $relativePath]);
                }
                if ($retries === 0) {
                    throw $e;
                }
                sleep(1);
                $this->httpClient->reset();
            }
        } while ($retries-- > 0);

        throw new \LogicException('Unreachable');
    }
}
