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

use App\Audit\AuditRecordType;
use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\SecurityAdvisory;
use App\Entity\Version;
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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Webmozart\Assert\Assert;

/**
 * v2 Metadata Dumper
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class V2Dumper
{
    use \App\Util\DoctrineTrait;

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
    public function dump(array $packageIds, bool $force = false, bool $verbose = false): void
    {
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

        if ($verbose) {
            echo 'Web dir is '.$webDir.'/p2 ('.realpath($webDir.'/p2').')'.\PHP_EOL;
            echo 'Build v2 dir is '.$buildDirV2.\PHP_EOL;
        }

        $dumpTimeUpdates = [];

        $versionRepo = $this->getEM()->getRepository(Version::class);

        $total = \count($packageIds);
        $current = 0;
        $step = 50;
        while ($packageIds) {
            $dumpTime = new \DateTime();
            $idBatch = array_splice($packageIds, 0, $step);
            $this->logger->debug('Dumping package ids', ['ids' => $idBatch]);
            $packages = $this->getEM()->getRepository(Package::class)->getPackagesWithVersions($idBatch);
            unset($idBatch);
            $packageNames = array_map(static fn (Package $pkg) => $pkg->getName(), $packages);
            $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->getAdvisoryIdsAndVersions($packageNames);

            if ($verbose) {
                echo '['.\sprintf('%'.\strlen((string) $total).'d', $current).'/'.$total.'] Processing '.$step.' packages'.\PHP_EOL;
            }

            $current += $step;

            // prepare packages in memory
            foreach ($packages as $package) {
                // skip spam packages in the dumper in case one appears due to a race condition
                if ($package->isFrozen() && $package->getFreezeReason() === PackageFreezeReason::Spam) {
                    continue;
                }

                // write versions in individual files
                $versionIds = [];
                foreach ($package->getVersions() as $version) {
                    $versionIds[] = $version->getId();
                }
                $versionData = $versionRepo->getVersionData($versionIds);

                // dump v2 format
                $this->dumpPackageToV2File($buildDirV2, $package, $versionData, $advisories[$package->getName()] ?? []);

                $dumpTimeUpdates[$dumpTime->format('Y-m-d H:i:s')][] = $package->getId();
            }

            unset($packages, $package, $version, $advisories, $packageNames);
            $this->getEM()->clear();
            $this->logger->reset();
        }

        if (!file_exists($webDir.'/p2') && !@symlink($buildDirV2, $webDir.'/p2')) {
            echo 'Warning: Could not symlink the build dir v2 into the web dir';
            throw new \RuntimeException('Could not symlink the build dir v2 into the web dir');
        }

        if ($verbose) {
            echo 'Updating package dump times'.\PHP_EOL;
        }

        $maxDumpTime = 0;
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
                    sleep(2);
                }
            }

            if ($dt !== '2100-01-01 00:00:00') {
                $maxDumpTime = max($maxDumpTime, strtotime($dt));
            }
        }

        if ($maxDumpTime !== 0) {
            $this->redis->set('last_metadata_dump_time', $maxDumpTime + 1);

            // make sure no next dumper has a chance to start and dump things within the same second as $maxDumpTime
            // as in updatedSince we will return the updates from the next second only (the +1 above) to avoid serving the same updates twice
            if (time() === $maxDumpTime) {
                sleep(1);
            }
        }
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
     */
    private function dumpPackageToV2File(string $dir, Package $package, array $versionData, array $advisories): void
    {
        $name = strtolower($package->getName());
        $forceDump = $package->getDumpedAtV2() === null;

        $versions = $package->getVersions()->toArray();
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

        $this->dumpVersionsToV2File($package, $name, $dir, $name.'.json', $name, $tags, $versionData, $forceDump, $advisories);
        $this->dumpVersionsToV2File($package, $name, $dir, $name.'~dev.json', $name, $branches, $versionData, $forceDump);
    }

    /**
     * @param array<array{advisoryId: string, affectedVersions: string}>|null $advisories
     * @param array<Version>                                                  $versions
     * @param VersionData                                                     $versionData
     */
    private function dumpVersionsToV2File(Package $package, string $name, string $dir, string $filename, string $packageName, array $versions, array $versionData, bool $forceDump, ?array $advisories = null): void
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

        $json = json_encode($metadata, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $this->writeV2File($package, $name, $path, $json, $forceDump);
    }

    private function writeV2File(Package $package, string $name, string $path, string $contents, bool $forceDump = false): void
    {
        if (
            !$forceDump
            && file_exists($path)
            && file_get_contents($path) === $contents
            // files dumped before then are susceptible to be out of sync, so force them all to be dumped once more at least
            && filemtime($path) >= 1606210609
        ) {
            return;
        }

        if (!Preg::isMatch('{/([^/]+/[^/]+?(~dev)?)\.json$}', $path, $match)) {
            throw new \LogicException('Could not match package name from '.$path);
        }

        // ensure we do not upload files to the cdn for packages that have been recently deleted to avoid race conditions
        $deletion = $this->redis->zscore('metadata-deletes', $name);
        if ($deletion !== null && $this->doctrine->getRepository(AuditRecord::class)->findOneBy(['packageId' => $package->getId(), 'type' => AuditRecordType::PackageDeleted]) !== null) {
            $this->logger->error('Skipped dumping a file as it is marked as having been deleted in the last 30seconds', ['file' => $path, 'deletion' => $deletion, 'time' => time()]);

            return;
        }

        $pkgWithDevFlag = $match[1];
        $relativePath = 'p2/'.$pkgWithDevFlag.'.json';
        $this->filesystem->mkdir(\dirname($path));

        $filemtime = $this->writeCdn($relativePath, $contents);

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

        $this->writeToReplica($path, $contents, $timeUnix);

        $this->purgeCdn($relativePath);

        $this->redis->zadd('metadata-dumps', [$pkgWithDevFlag => $filemtime]);
        $this->statsd->increment('packagist.metadata_dump_v2');
    }

    /**
     * @param non-empty-string $relativePath
     *
     * @throws TransportExceptionInterface
     */
    private function writeCdn(string $relativePath, string $contents): int
    {
        $retries = 3;
        do {
            try {
                $filemtime = $this->cdnClient->uploadMetadata($relativePath, $contents);

                return $filemtime;
            } catch (TransportExceptionInterface $e) {
                if ($retries === 0) {
                    throw $e;
                }
                $this->logger->debug('Retrying due to failure', ['exception' => $e]);
                sleep(1);
            }
        } while ($retries-- > 0);

        throw $e;
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
                    throw $e;
                }
                $this->logger->debug('Retrying due to failure', ['exception' => $e]);
                sleep(1);
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
        } while ($retries-- > 0);
    }
}
