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

use App\Entity\PackageFreezeReason;
use App\Entity\SecurityAdvisory;
use Composer\Pcre\Preg;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\Filesystem\Filesystem;
use Composer\MetadataMinifier\MetadataMinifier;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Finder\Finder;
use App\Entity\Version;
use App\Entity\Package;
use App\Model\ProviderManager;
use Doctrine\DBAL\Connection;
use App\HealthCheck\MetadataDirCheck;
use Predis\Client;
use Graze\DogStatsD\Client as StatsDClient;
use Monolog\Logger;
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
        /** @var AwsMetadata */
        private array $awsMetadata,
        private StatsDClient $statsd,
        private ProviderManager $providerManager,
        private Logger $logger,
    ) {
        $webDir = realpath($webDir);
        Assert::string($webDir);
        $this->webDir = $webDir;
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
            echo 'Web dir is '.$webDir.'/p2 ('.realpath($webDir.'/p2').')'.PHP_EOL;
            echo 'Build v2 dir is '.$buildDirV2.PHP_EOL;
        }

        $dumpTimeUpdates = [];

        $versionRepo = $this->getEM()->getRepository(Version::class);

        $total = count($packageIds);
        $current = 0;
        $step = 50;
        while ($packageIds) {
            $dumpTime = new \DateTime;
            $packages = $this->getEM()->getRepository(Package::class)->getPackagesWithVersions(array_splice($packageIds, 0, $step));
            $packageNames = array_map(static fn (Package $pkg) => $pkg->getName(), $packages);
            $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->getAdvisoryIdsAndVersions($packageNames);

            if ($verbose) {
                echo '['.sprintf('%'.strlen((string) $total).'d', $current).'/'.$total.'] Processing '.$step.' packages'.PHP_EOL;
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
            echo 'Updating package dump times'.PHP_EOL;
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

        $this->statsd->increment('packagist.metadata_dump_v2');
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

    /**
     * @param mixed[] $versionData
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

        $this->dumpVersionsToV2File($dir, $name.'.json', $name, $tags, $versionData, $forceDump, $advisories);
        $this->dumpVersionsToV2File($dir, $name.'~dev.json', $name, $branches, $versionData, $forceDump);
    }

    /**
     * @param array<array{advisoryId: string, affectedVersions: string}>|null $advisories
     * @param array<Version> $versions
     * @param VersionData $versionData
     */
    private function dumpVersionsToV2File(string $dir, string $filename, string $packageName, array $versions, array $versionData, bool $forceDump, array|null $advisories = null): void
    {
        $versionArrays = [];
        foreach ($versions as $version) {
            $versionArrays[] = $version->toV2Array($versionData);
        }

        $path = $dir . '/' . $filename;

        $metadata = [
            'minified' => 'composer/2.0',
            'packages' => [
                $packageName => MetadataMinifier::minify($versionArrays),
            ],
        ];

        if ($advisories !== null) {
            $metadata['security-advisories'] = $advisories;
        }

        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->writeV2File($path, $json, $forceDump);
    }

    private function writeV2File(string $path, string $contents, bool $forceDump = false): void
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

        $this->filesystem->mkdir(dirname($path));

        // get time before file_put_contents to be sure we return a time at least as old as the filemtime, if it is older it doesn't matter
        $timestamp = round(microtime(true) * 10000);
        file_put_contents($path.'.tmp', $contents);
        rename($path.'.tmp', $path);

        if (!Preg::isMatch('{/([^/]+/[^/]+?(~dev)?)\.json$}', $path, $match)) {
            throw new \LogicException('Could not match package name from '.$path);
        }

        $this->redis->zadd('metadata-dumps', [$match[1] => $timestamp]);
    }
}
