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

namespace App\Package;

use Symfony\Component\Filesystem\Filesystem;
use Composer\Util\Filesystem as ComposerFilesystem;
use Composer\Util\MetadataMinifier;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Finder\Finder;
use App\Entity\Version;
use App\Entity\Package;
use App\Model\ProviderManager;
use Doctrine\DBAL\Connection;
use App\HealthCheck\MetadataDirCheck;
use Predis\Client;
use Graze\DogStatsD\Client as StatsDClient;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class SymlinkDumper
{
    /**
     * Doctrine
     * @var ManagerRegistry
     */
    protected $doctrine;

    /**
     * @var Filesystem
     */
    protected $fs;

    /**
     * @var ComposerFilesystem
     */
    protected $cfs;

    /**
     * @var string
     */
    protected $webDir;

    /**
     * @var string
     */
    protected $buildDir;

    /**
     * @var UrlGeneratorInterface
     */
    protected $router;

    /**
     * @var Client
     */
    protected $redis;

    /**
     * Data cache
     * @var array
     */
    private $rootFile;

    /**
     * Data cache
     * @var array
     */
    private $listings = array();

    /**
     * Data cache
     * @var array
     */
    private $individualFiles = array();

    /**
     * Data cache
     * @var array
     */
    private $individualFilesV2 = array();

    /**
     * Modified times of individual files
     * @var array
     */
    private $individualFilesMtime = array();

    /**
     * Stores all the disk writes to be replicated in the second build dir after the symlink has been swapped
     * @var array
     */
    private $writeLog = array();

    /**
     * Generate compressed files.
     * @var int 0 disabled, 9 maximum.
     */
    private $compress;

    /**
     * @var array
     */
    private $awsMeta;

    /**
     * @var StatsDClient
     */
    private $statsd;

    /**
     * @var ProviderManager
     */
    private $providerManager;

    /**
     * Constructor
     *
     * @param ManagerRegistry       $doctrine
     * @param Filesystem            $filesystem
     * @param UrlGeneratorInterface $router
     * @param string                $webDir     web root
     * @param string                $targetDir
     * @param int                   $compress
     */
    public function __construct(ManagerRegistry $doctrine, Filesystem $filesystem, UrlGeneratorInterface $router, Client $redis, $webDir, $targetDir, $compress, $awsMetadata, StatsDClient $statsd, ProviderManager $providerManager)
    {
        $this->doctrine = $doctrine;
        $this->fs = $filesystem;
        $this->cfs = new ComposerFilesystem;
        $this->router = $router;
        $this->webDir = realpath($webDir);
        $this->buildDir = $targetDir;
        $this->compress = $compress;
        $this->redis = $redis;
        $this->awsMeta = $awsMetadata;
        $this->statsd = $statsd;
        $this->providerManager = $providerManager;
    }

    /**
     * Dump a set of packages to the web root
     *
     * @param array   $packageIds
     * @param Boolean $force
     * @param Boolean $verbose
     */
    public function dump(array $packageIds, $force = false, $verbose = false)
    {
        if (!MetadataDirCheck::isMetadataStoreMounted($this->awsMeta)) {
            throw new \RuntimeException('Metadata store not mounted, can not dump metadata');
        }

        // prepare build dir
        $webDir = $this->webDir;

        $buildDirA = $this->buildDir.'/a';
        $buildDirB = $this->buildDir.'/b';
        $buildDirV2 = $this->buildDir.'/p2';

        // initialize
        $initialRun = false;
        if (!is_dir($buildDirA) || !is_dir($buildDirB)) {
            $initialRun = true;
            if (!$this->removeDirectory($buildDirA) || !$this->removeDirectory($buildDirB)) {
                throw new \RuntimeException('Failed to delete '.$buildDirA.' or '.$buildDirB);
            }
            $this->fs->mkdir($buildDirA);
            $this->fs->mkdir($buildDirB);
        }
        if (!is_dir($buildDirV2)) {
            $this->fs->mkdir($buildDirV2);
        }

        // set build dir to the not-active one
        if (realpath($webDir.'/p') === realpath($buildDirA)) {
            $buildDir = realpath($buildDirB);
            $oldBuildDir = realpath($buildDirA);
        } else {
            $buildDir = realpath($buildDirA);
            $oldBuildDir = realpath($buildDirB);
        }
        $buildDirV2 = realpath($buildDirV2);

        // copy existing stuff for smooth BC transition
        if ($initialRun && !$force) {
            if (!file_exists($webDir.'/p') || is_link($webDir.'/p')) {
                @rmdir($buildDir);
                @rmdir($oldBuildDir);
                throw new \RuntimeException('Run this again with --force the first time around to make sure it dumps all packages');
            }
            if ($verbose) {
                echo 'Copying existing files'.PHP_EOL;
            }

            foreach (array($buildDir, $oldBuildDir) as $dir) {
                $this->cloneDir($webDir.'/p', $dir);
            }
        }

        if ($verbose) {
            echo 'Web dir is '.$webDir.'/p ('.realpath($webDir.'/p').')'.PHP_EOL;
            echo 'Build dir is '.$buildDir.PHP_EOL;
            echo 'Build v2 dir is '.$buildDirV2.PHP_EOL;
        }

        // clean the build dir to start over if we are re-dumping everything
        if ($force) {
            // disable the write log since we copy everything at the end in forced mode
            $this->writeLog = false;

            if ($verbose) {
                echo 'Cleaning up existing files'.PHP_EOL;
            }
            if (!$this->clearDirectory($buildDir)) {
                return false;
            }
        }

        $dumpTimeUpdates = [];

        $versionRepo = $this->doctrine->getRepository(Version::class);

        try {
            $modifiedIndividualFiles = array();
            $modifiedV2Files = array();

            $total = count($packageIds);
            $current = 0;
            $step = 50;
            while ($packageIds) {
                $dumpTime = new \DateTime;
                $packages = $this->doctrine->getRepository(Package::class)->getPackagesWithVersions(array_splice($packageIds, 0, $step));

                if ($verbose) {
                    echo '['.sprintf('%'.strlen($total).'d', $current).'/'.$total.'] Processing '.$step.' packages'.PHP_EOL;
                }

                $current += $step;

                // prepare packages in memory
                foreach ($packages as $package) {
                    // skip spam packages in the dumper in case we do a forced full dump and prevent them from being dumped for a little while
                    if ($package->isAbandoned() && $package->getReplacementPackage() === 'spam/spam') {
                        $dumpTimeUpdates['2100-01-01 00:00:00'][] = $package->getId();
                        continue;
                    }

                    $affectedFiles = array();
                    $name = strtolower($package->getName());

                    // clean up versions in individual files
                    if (file_exists($buildDir.'/'.$name.'.files')) {
                        $files = json_decode(file_get_contents($buildDir.'/'.$name.'.files'));

                        foreach ($files as $file) {
                            if (substr_count($file, '/') > 1) { // handle old .files with p/*/*.json paths
                                $file = preg_replace('{^p/}', '', $file);
                            }
                            $this->loadIndividualFile($buildDir.'/'.$file, $file);
                            if (isset($this->individualFiles[$file]['packages'][$name])) {
                                unset($this->individualFiles[$file]['packages'][$name]);
                                $modifiedIndividualFiles[$file] = true;
                            }
                        }
                    }

                    // (re)write versions in individual files
                    $versionIds = [];
                    foreach ($package->getVersions() as $version) {
                        $versionIds[] = $version->getId();
                    }
                    $versionData = $versionRepo->getVersionData($versionIds);
                    foreach ($package->getVersions() as $version) {
                        foreach (array_slice($version->getNames($versionData), 0, 150) as $versionName) {
                            if (!preg_match('{^[A-Za-z0-9_-][A-Za-z0-9_.-]*/[A-Za-z0-9_-][A-Za-z0-9_.-]*$}', $versionName) || strpos($versionName, '..')) {
                                continue;
                            }

                            $file = $buildDir.'/'.$versionName.'.json';
                            $key = $versionName.'.json';
                            $this->dumpVersionToIndividualFile($version, $file, $key, $versionData);
                            $modifiedIndividualFiles[$key] = true;
                            $affectedFiles[$key] = true;
                        }

                    }

                    // dump v2 format
                    $key = $name.'.json';
                    $keyDev = $name.'~dev.json';
                    $forceDump = $package->getDumpedAt() === null;
                    $this->dumpPackageToV2File($package, $versionData, $key, $keyDev, $forceDump);
                    $modifiedV2Files[$key] = true;
                    $modifiedV2Files[$keyDev] = true;

                    // store affected files to clean up properly in the next update
                    $this->fs->mkdir(dirname($buildDir.'/'.$name));
                    $this->writeFileNonAtomic($buildDir.'/'.$name.'.files', json_encode(array_keys($affectedFiles)));

                    $dumpTimeUpdates[$dumpTime->format('Y-m-d H:i:s')][] = $package->getId();
                }

                unset($packages, $package, $version);
                $this->doctrine->getManager()->clear();

                if ($current % 250 === 0 || !$packageIds) {
                    if ($verbose) {
                        echo 'Dumping individual files'.PHP_EOL;
                    }
                    $this->dumpIndividualFiles($buildDir);
                    $this->dumpIndividualFilesV2($buildDirV2);
                }
            }

            // prepare individual files listings
            if ($verbose) {
                echo 'Preparing individual files listings'.PHP_EOL;
            }
            $individualHashedListings = array();
            $finder = Finder::create()->files()->ignoreVCS(true)->name('*.json')->in($buildDir)->depth('1');

            foreach ($finder as $file) {
                // skip hashed files
                if (strpos($file, '$')) {
                    continue;
                }

                $key = basename(dirname($file)).'/'.basename($file);
                if ($force && !isset($modifiedIndividualFiles[$key])) {
                    continue;
                }

                // add hashed provider to listing
                $listing = $this->getTargetListing($file);
                $hash = hash_file('sha256', $file);
                $key = substr($key, 0, -5);
                $this->listings[$listing]['providers'][$key] = array('sha256' => $hash);
                $individualHashedListings[$listing] = true;
            }

            // prepare root file
            $rootFile = $buildDir.'/packages.json';
            $this->rootFile = array('packages' => array());
            $url = $this->router->generate('track_download', ['name' => 'VND/PKG'], UrlGeneratorInterface::ABSOLUTE_URL);
            $this->rootFile['notify'] = str_replace('VND/PKG', '%package%', $url);
            $this->rootFile['notify-batch'] = $this->router->generate('track_download_batch', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $this->rootFile['providers-url'] = $this->router->generate('home', []) . 'p/%package%$%hash%.json';
            $this->rootFile['metadata-url'] = $this->router->generate('home', []) . 'p2/%package%.json';
            $this->rootFile['search'] = $this->router->generate('search', ['_format' => 'json'], UrlGeneratorInterface::ABSOLUTE_URL) . '?q=%query%&type=%type%';
            $this->rootFile['list'] = $this->router->generate('list', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $this->rootFile['providers-api'] = str_replace('VND/PKG', '%package%', $this->router->generate('view_providers', ['name' => 'VND/PKG', '_format' => 'json'], UrlGeneratorInterface::ABSOLUTE_URL));
            $this->rootFile['warning'] = 'You are using an outdated version of Composer. Composer 2.0 is now available and you should upgrade. See https://getcomposer.org/2';
            $this->rootFile['warning-versions'] = '<1.10.10';

            if ($verbose) {
                echo 'Dumping individual listings'.PHP_EOL;
            }

            // dump listings to build dir
            foreach ($individualHashedListings as $listing => $dummy) {
                list($listingPath, $hash) = $this->dumpListing($buildDir.'/'.$listing);
                $hashedListing = basename($listingPath);
                $this->rootFile['provider-includes']['p/'.str_replace($hash, '%hash%', $hashedListing)] = array('sha256' => $hash);
            }

            if ($verbose) {
                echo 'Dumping root'.PHP_EOL;
            }
            $this->dumpRootFile($rootFile);
        } catch (\Exception $e) {
            // restore files as they were before we started
            $this->cloneDir($oldBuildDir, $buildDir);
            throw $e;
        }

        try {
            if ($verbose) {
                echo 'Putting new files in production'.PHP_EOL;
            }

            if (!file_exists($webDir.'/p2') && !@symlink($buildDirV2, $webDir.'/p2')) {
                echo 'Warning: Could not symlink the build dir v2 into the web dir';
                throw new \RuntimeException('Could not symlink the build dir v2 into the web dir');
            }

            // move away old files for BC update
            if ($initialRun && file_exists($webDir.'/p') && !is_link($webDir.'/p')) {
                rename($webDir.'/p', $webDir.'/p-old');
            }

            $this->switchActiveWebDir($webDir, $buildDir);
        } catch (\Exception $e) {
            @symlink($oldBuildDir, $webDir.'/p');
            throw $e;
        }

        try {
            if ($initialRun || !is_link($webDir.'/packages.json') || $force) {
                if ($verbose) {
                    echo 'Writing/linking the packages.json'.PHP_EOL;
                }
                if (file_exists($webDir.'/packages.json')) {
                    unlink($webDir.'/packages.json');
                }
                if (file_exists($webDir.'/packages.json.gz')) {
                    unlink($webDir.'/packages.json.gz');
                }
                if (defined('PHP_WINDOWS_VERSION_BUILD')) {
                    $sourcePath = $buildDir.'/packages.json';
                    if (!copy($sourcePath, $webDir.'/packages.json')) {
                        throw new \RuntimeException('Could not copy the packages.json file');
                    }
                } else {
                    $sourcePath = 'p/packages.json';
                    if (!symlink($sourcePath, $webDir.'/packages.json')) {
                        throw new \RuntimeException('Could not symlink the packages.json file');
                    }
                    if ($this->compress && !symlink($sourcePath.'.gz', $webDir.'/packages.json.gz')) {
                        throw new \RuntimeException('Could not symlink the packages.json.gz file');
                    }
                }
            }
        } catch (\Exception $e) {
            $this->switchActiveWebDir($webDir, $oldBuildDir);
            throw $e;
        }

        // clean up old dir if present on BC update
        if ($initialRun) {
            $this->removeDirectory($webDir.'/p-old');
        }

        // clean the old build dir if we re-dumped everything
        if ($force) {
            if ($verbose) {
                echo 'Cleaning up old build dir'.PHP_EOL;
            }
            if (!$this->clearDirectory($oldBuildDir)) {
                throw new \RuntimeException('Unrecoverable inconsistent state (old build dir could not be cleared), run with --force again to retry');
            }
        }

        // copy state to old active dir
        if ($force) {
            if ($verbose) {
                echo 'Copying new contents to old build dir to sync up'.PHP_EOL;
            }
            $this->cloneDir($buildDir, $oldBuildDir);
        } else {
            if ($verbose) {
                echo 'Replaying write log in old build dir'.PHP_EOL;
            }
            $this->copyWriteLog($buildDir, $oldBuildDir);
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
                    $this->doctrine->getManager()->getConnection()->executeQuery(
                        'UPDATE package SET dumpedAt=:dumped WHERE id IN (:ids)',
                        [
                            'ids' => $ids,
                            'dumped' => $dt,
                        ],
                        ['ids' => Connection::PARAM_INT_ARRAY]
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

        $this->statsd->increment('packagist.metadata_dump');

        return true;
    }

    private function switchActiveWebDir($webDir, $buildDir)
    {
        $newLink = $webDir.'/p-new';
        $oldLink = $webDir.'/p';

        if (file_exists($newLink)) {
            unlink($newLink);
        }
        if (!symlink($buildDir, $newLink)) {
            echo 'Warning: Could not symlink the build dir into the web dir';
            throw new \RuntimeException('Could not symlink the build dir into the web dir');
        }
        if (!rename($newLink, $oldLink)) {
            echo 'Warning: Could not replace the old symlink with the new one in the web dir';
            throw new \RuntimeException('Could not replace the old symlink with the new one in the web dir');
        }
    }

    private function cloneDir($source, $target)
    {
        $this->removeDirectory($target);
        exec('cp -rpf '.escapeshellarg($source).' '.escapeshellarg($target), $output, $exit);
        if (0 !== $exit) {
            echo 'Warning, cloning a directory using the php fallback does not keep filemtime, invalid behavior may occur';
            $this->fs->mirror($source, $target, null, array('override' => true));
        }
    }

    public function gc()
    {
        // clean up v2 files for deleted packages
        if (is_dir($this->buildDir.'/p2')) {
            $finder = Finder::create()->directories()->ignoreVCS(true)->in($this->buildDir.'/p2');
            $packageNames = array_flip($this->providerManager->getPackageNames());

            foreach ($finder as $vendorDir) {
                foreach (glob(((string) $vendorDir).'/*.json') as $file) {
                    if (!preg_match('{/([^/]+/[^/]+?)(~dev)?\.json$}', strtr($file, '\\', '/'), $match)) {
                        throw new \LogicException('Could not match package name from '.$path);
                    }

                    if (!isset($packageNames[$match[1]])) {
                        unlink((string) $file);
                    }
                }
            }
        }

        // build up array of safe files
        $safeFiles = [];

        $rootFile = $this->webDir.'/packages.json';
        if (!file_exists($rootFile) || !is_dir($this->buildDir.'/a')) {
            return;
        }
        $rootJson = json_decode(file_get_contents($rootFile), true);
        foreach ($rootJson['provider-includes'] as $listing => $opts) {
            $listing = str_replace('%hash%', $opts['sha256'], $listing);
            $safeFiles[basename($listing)] = true;

            $listingJson = json_decode(file_get_contents($this->webDir.'/'.$listing), true);
            foreach ($listingJson['providers'] as $pkg => $opts) {
                $provPath = $pkg.'$'.$opts['sha256'].'.json';
                $safeFiles[$provPath] = true;
            }
        }

        $buildDirs = [realpath($this->buildDir.'/a'), realpath($this->buildDir.'/b')];
        shuffle($buildDirs);

        $this->cleanOldFiles($buildDirs[0], $buildDirs[1], $safeFiles);
    }

    private function cleanOldFiles($buildDir, $oldBuildDir, $safeFiles)
    {
        $time = (time() - 86400) * 10000;
        $this->redis->set('metadata-oldest', $time);
        $this->redis->zremrangebyscore('metadata-dumps', 0, $time-1);
        $this->redis->zremrangebyscore('metadata-deletes', 0, $time-1);

        $finder = Finder::create()->directories()->ignoreVCS(true)->in($buildDir);
        foreach ($finder as $vendorDir) {
            $vendorFiles = Finder::create()->files()->ignoreVCS(true)
                ->name('/\$[a-f0-9]+\.json$/')
                ->date('until 10minutes ago')
                ->in((string) $vendorDir);

            foreach ($vendorFiles as $file) {
                $key = strtr(str_replace($buildDir.DIRECTORY_SEPARATOR, '', $file), '\\', '/');
                if (!isset($safeFiles[$key])) {
                    unlink((string) $file);
                    if (file_exists($altDirFile = str_replace($buildDir, $oldBuildDir, (string) $file))) {
                        unlink($altDirFile);
                    }
                }
            }
        }

        // clean up old provider listings
        $finder = Finder::create()->depth(0)->files()->name('provider-*.json')->ignoreVCS(true)->in($buildDir)->date('until 10minutes ago');
        foreach ($finder as $provider) {
            $key = strtr(str_replace($buildDir.DIRECTORY_SEPARATOR, '', $provider), '\\', '/');
            if (!isset($safeFiles[$key])) {
                $path = (string) $provider;
                unlink($path);
                if (file_exists($path.'.gz')) {
                    unlink($path.'.gz');
                }
                if (file_exists($altDirFile = str_replace($buildDir, $oldBuildDir, $path))) {
                    unlink($altDirFile);
                    if (file_exists($altDirFile.'.gz')) {
                        unlink($altDirFile.'.gz');
                    }
                }
            }
        }

        // clean up old root listings
        $finder = Finder::create()->depth(0)->files()->name('packages.json-*')->ignoreVCS(true)->in($buildDir)->date('until 10minutes ago');
        foreach ($finder as $rootFile) {
            $path = (string) $rootFile;
            unlink($path);
            if (file_exists($path.'.gz')) {
                unlink($path.'.gz');
            }
            if (file_exists($altDirFile = str_replace($buildDir, $oldBuildDir, $path))) {
                unlink($altDirFile);
                if (file_exists($altDirFile.'.gz')) {
                    unlink($altDirFile.'.gz');
                }
            }
        }
    }

    private function dumpRootFile($file)
    {
        // sort all versions and packages to make sha1 consistent
        ksort($this->rootFile['packages']);
        ksort($this->rootFile['provider-includes']);
        foreach ($this->rootFile['packages'] as $package => $versions) {
            ksort($this->rootFile['packages'][$package]);
        }

        $json = json_encode($this->rootFile, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $time = time();

        $this->writeFile($file, $json, $time);
        if ($this->compress) {
            $this->writeFile($file . '.gz', gzencode($json, $this->compress), $time);
        }
    }

    private function dumpListing($path)
    {
        $key = basename($path);

        // sort files to make hash consistent
        ksort($this->listings[$key]['providers']);

        $json = json_encode($this->listings[$key], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $json);
        $path = substr($path, 0, -5) . '$' . $hash . '.json';
        $time = time();

        if (!file_exists($path)) {
            $this->writeFile($path, $json, $time);
            if ($this->compress) {
                $this->writeFile($path . '.gz', gzencode($json, $this->compress), $time);
            }
        }

        return array($path, $hash);
    }

    private function loadIndividualFile($path, $key)
    {
        if (isset($this->individualFiles[$key])) {
            return;
        }

        if (file_exists($path)) {
            $this->individualFiles[$key] = json_decode(file_get_contents($path), true);
            $this->individualFilesMtime[$key] = filemtime($path);
        } else {
            $this->individualFiles[$key] = array();
            $this->individualFilesMtime[$key] = 0;
        }
    }

    private function dumpIndividualFiles($buildDir)
    {
        // dump individual files to build dir
        foreach ($this->individualFiles as $file => $dummy) {
            $this->dumpIndividualFile($buildDir.'/'.$file, $file);
        }

        $this->individualFiles = array();
        $this->individualFilesMtime = array();
    }

    private function dumpIndividualFile($path, $key)
    {
        // sort all versions and packages to make sha1 consistent
        ksort($this->individualFiles[$key]['packages']);
        foreach ($this->individualFiles[$key]['packages'] as $package => $versions) {
            ksort($this->individualFiles[$key]['packages'][$package]);
        }

        $this->fs->mkdir(dirname($path));

        $json = json_encode($this->individualFiles[$key], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $this->writeFile($path, $json, $this->individualFilesMtime[$key]);

        // write the hashed provider file
        $hashedFile = substr($path, 0, -5) . '$' . hash('sha256', $json) . '.json';
        $this->writeFile($hashedFile, $json);
    }

    private function dumpVersionToIndividualFile(Version $version, $file, $key, $versionData)
    {
        $this->loadIndividualFile($file, $key);
        $data = $version->toArray($versionData);
        $data['uid'] = $version->getId();
        if (in_array($data['version_normalized'], ['dev-master', 'dev-default', 'dev-trunk'], true)) {
            $data['version_normalized'] = '9999999-dev';
        }
        $this->individualFiles[$key]['packages'][strtolower($version->getName())][$version->getVersion()] = $data;
        $timestamp = $version->getReleasedAt() ? $version->getReleasedAt()->getTimestamp() : time();
        if (!isset($this->individualFilesMtime[$key]) || $this->individualFilesMtime[$key] < $timestamp) {
            $this->individualFilesMtime[$key] = $timestamp;
        }
    }

    private function dumpIndividualFilesV2($dir)
    {
        // dump individual files to build dir
        foreach ($this->individualFilesV2 as $key => $data) {
            $path = $dir . '/' . $key;
            $this->fs->mkdir(dirname($path));

            $json = json_encode($data['metadata'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $this->writeV2File($path, $json, $data['force_dump']);
        }

        $this->individualFilesV2 = array();
    }

    private function dumpPackageToV2File(Package $package, $versionData, string $packageKey, string $packageKeyDev, bool $forceDump)
    {
        $versions = $package->getVersions();
        if (is_object($versions)) {
            $versions = $versions->toArray();
        }

        usort($versions, Package::class.'::sortVersions');

        $tags = [];
        $branches = [];
        foreach ($versions as $version) {
            if ($version->isDevelopment()) {
                $branches[] = $version;
            } else {
                $tags[] = $version;
            }
        }

        $this->dumpVersionsToV2File($package, $tags, $versionData, $packageKey, $forceDump);
        $this->dumpVersionsToV2File($package, $branches, $versionData, $packageKeyDev, $forceDump);
    }

    private function dumpVersionsToV2File(Package $package, $versions, $versionData, string $packageKey, bool $forceDump)
    {
        $versionArrays = [];
        foreach ($versions as $version) {
            $versionArrays[] = $version->toV2Array($versionData);
        }

        $minifiedVersions = MetadataMinifier::minify($versionArrays);

        $this->individualFilesV2[$packageKey] = [
            'force_dump' => $forceDump,
            'metadata' => [
                'packages' => [
                    strtolower($package->getName()) => $minifiedVersions
                ],
                'minified' => 'composer/2.0',
            ]
        ];
    }

    private function clearDirectory($path)
    {
        if (!$this->removeDirectory($path)) {
            echo 'Could not remove the build dir entirely, aborting';

            return false;
        }
        $this->fs->mkdir($path);
        return true;
    }

    private function removeDirectory($path)
    {
        $retries = 5;
        do {
            if (!$this->cfs->removeDirectory($path)) {
                usleep(200);
            }
            clearstatcache();
        } while (is_dir($path) && $retries--);

        return !is_dir($path);
    }

    private function getTargetListingBlocks($now)
    {
        $blocks = array();

        // monday last week
        $blocks['latest'] = strtotime('monday last week', $now);

        $month = date('n', $now);
        $month = ceil($month / 3) * 3 - 2; // 1 for months 1-3, 10 for months 10-12
        $block = new \DateTime(date('Y', $now).'-'.$month.'-01'); // 1st day of current trimester

        // split last 12 months in 4 trimesters
        for ($i=0; $i < 4; $i++) {
            $blocks[$block->format('Y-m')] = $block->getTimestamp();
            $block->sub(new \DateInterval('P3M'));
        }

        $year = (int) $block->format('Y');

        while ($year >= 2013) {
            $blocks[''.$year] = strtotime($year.'-01-01');
            $year--;
        }

        return $blocks;
    }

    private function getTargetListing($file)
    {
        static $blocks;

        if (!$blocks) {
            $blocks = $this->getTargetListingBlocks(time());
        }

        $mtime = filemtime($file);

        foreach ($blocks as $label => $block) {
            if ($mtime >= $block) {
                return "provider-${label}.json";
            }
        }

        return "provider-archived.json";
    }

    private function writeFile($path, $contents, $mtime = null)
    {
        file_put_contents($path.'.tmp', $contents);
        if ($mtime !== null) {
            touch($path.'.tmp', $mtime);
        }
        rename($path.'.tmp', $path);

        if (is_array($this->writeLog)) {
            $this->writeLog[$path] = array($contents, $mtime);
        }
    }

    private function writeV2File($path, $contents, $forceDump = false)
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

        // get time before file_put_contents to be sure we return a time at least as old as the filemtime, if it is older it doesn't matter
        $timestamp = round(microtime(true)*10000);
        file_put_contents($path.'.tmp', $contents);
        rename($path.'.tmp', $path);

        if (!preg_match('{/([^/]+/[^/]+?(~dev)?)\.json$}', $path, $match)) {
            throw new \LogicException('Could not match package name from '.$path);
        }

        $this->redis->zadd('metadata-dumps', $timestamp, $match[1]);
    }

    private function writeFileNonAtomic($path, $contents)
    {
        file_put_contents($path, $contents);

        if (is_array($this->writeLog)) {
            $this->writeLog[$path] = array($contents, null);
        }
    }

    private function copyWriteLog($from, $to)
    {
        foreach ($this->writeLog as $path => $op) {
            $path = str_replace($from, $to, $path);

            $this->fs->mkdir(dirname($path));
            file_put_contents($path, $op[0]);
            if ($op[1] !== null) {
                touch($path, $op[1]);
            }
        }
    }
}
