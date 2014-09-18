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

namespace Packagist\WebBundle\Package;

use Symfony\Component\Filesystem\Filesystem;
use Composer\Util\Filesystem as ComposerFilesystem;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Finder\Finder;
use Packagist\WebBundle\Entity\Version;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class SymlinkDumper
{
    /**
     * Doctrine
     * @var RegistryInterface
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
     * Constructor
     *
     * @param RegistryInterface     $doctrine
     * @param Filesystem            $filesystem
     * @param UrlGeneratorInterface $router
     * @param string                $webDir     web root
     * @param string                $targetDir
     */
    public function __construct(RegistryInterface $doctrine, Filesystem $filesystem, UrlGeneratorInterface $router, $webDir, $targetDir)
    {
        $this->doctrine = $doctrine;
        $this->fs = $filesystem;
        $this->cfs = new ComposerFilesystem;
        $this->router = $router;
        $this->webDir = realpath($webDir);
        $this->buildDir = $targetDir;
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
        $cleanUpOldFiles = date('i') == 0;

        // prepare build dir
        $webDir = $this->webDir;

        $buildDirA = $this->buildDir.'/a';
        $buildDirB = $this->buildDir.'/b';

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

        // set build dir to the not-active one
        if (realpath($webDir.'/p') === realpath($buildDirA)) {
            $buildDir = realpath($buildDirB);
            $oldBuildDir = realpath($buildDirA);
        } else {
            $buildDir = realpath($buildDirA);
            $oldBuildDir = realpath($buildDirB);
        }

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

        try {
            $modifiedIndividualFiles = array();

            $total = count($packageIds);
            $current = 0;
            $step = 50;
            while ($packageIds) {
                $dumpTime = new \DateTime;
                $packages = $this->doctrine->getRepository('PackagistWebBundle:Package')->getPackagesWithVersions(array_splice($packageIds, 0, $step));

                if ($verbose) {
                    echo '['.sprintf('%'.strlen($total).'d', $current).'/'.$total.'] Processing '.$step.' packages'.PHP_EOL;
                }

                $current += $step;

                // prepare packages in memory
                foreach ($packages as $package) {
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
                    foreach ($package->getVersions() as $version) {
                        foreach (array_slice($version->getNames(), 0, 150) as $versionName) {
                            if (!preg_match('{^[A-Za-z0-9_-][A-Za-z0-9_.-]*/[A-Za-z0-9_-][A-Za-z0-9_.-]*$}', $versionName) || strpos($versionName, '..')) {
                                continue;
                            }

                            $file = $buildDir.'/'.$versionName.'.json';
                            $key = $versionName.'.json';
                            $this->dumpVersionToIndividualFile($version, $file, $key);
                            $modifiedIndividualFiles[$key] = true;
                            $affectedFiles[$key] = true;
                        }
                    }

                    // store affected files to clean up properly in the next update
                    $this->fs->mkdir(dirname($buildDir.'/'.$name));
                    $this->writeFile($buildDir.'/'.$name.'.files', json_encode(array_keys($affectedFiles)));

                    $package->setDumpedAt($dumpTime);
                }

                // update dump dates
                $this->doctrine->getManager()->flush();
                unset($packages, $package, $version);
                $this->doctrine->getManager()->clear();

                if ($current % 250 === 0 || !$packageIds) {
                    if ($verbose) {
                        echo 'Dumping individual files'.PHP_EOL;
                    }
                    $this->dumpIndividualFiles($buildDir);
                }
            }

            // prepare individual files listings
            if ($verbose) {
                echo 'Preparing individual files listings'.PHP_EOL;
            }
            $safeFiles = array();
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
                $safeFiles[] = $key.'$'.$hash.'.json';
                $this->listings[$listing]['providers'][$key] = array('sha256' => $hash);
                $individualHashedListings[$listing] = true;
            }

            // prepare root file
            $rootFile = $buildDir.'/packages.json';
            $this->rootFile = array('packages' => array());
            $url = $this->router->generate('track_download', array('name' => 'VND/PKG'));
            $this->rootFile['notify'] = str_replace('VND/PKG', '%package%', $url);
            $this->rootFile['notify-batch'] = $this->router->generate('track_download_batch');
            $this->rootFile['providers-url'] = $this->router->generate('home') . 'p/%package%$%hash%.json';
            $this->rootFile['search'] = $this->router->generate('search', array('_format' => 'json')) . '?q=%query%';

            if ($verbose) {
                echo 'Dumping individual listings'.PHP_EOL;
            }

            // dump listings to build dir
            foreach ($individualHashedListings as $listing => $dummy) {
                list($listingPath, $hash) = $this->dumpListing($buildDir.'/'.$listing);
                $hashedListing = basename($listingPath);
                $this->rootFile['provider-includes']['p/'.str_replace($hash, '%hash%', $hashedListing)] = array('sha256' => $hash);
                $safeFiles[] = $hashedListing;
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

        // clean up old files once an hour
        if (!$force && $cleanUpOldFiles) {
            if ($verbose) {
                echo 'Cleaning up old files'.PHP_EOL;
            }

            $this->cleanOldFiles($buildDir, $oldBuildDir, $safeFiles);
        }

        return true;
    }

    private function switchActiveWebDir($webDir, $buildDir)
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            @rmdir($webDir.'/p');
        } else {
            @unlink($webDir.'/p');
        }
        if (!symlink($buildDir, $webDir.'/p')) {
            throw new \RuntimeException('Could not symlink the build dir into the web dir');
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

    private function cleanOldFiles($buildDir, $oldBuildDir, $safeFiles)
    {
        $finder = Finder::create()->directories()->ignoreVCS(true)->in($buildDir);
        foreach ($finder as $vendorDir) {
            $vendorFiles = Finder::create()->files()->ignoreVCS(true)
                ->name('/\$[a-f0-9]+\.json$/')
                ->date('until 10minutes ago')
                ->in((string) $vendorDir);

            foreach ($vendorFiles as $file) {
                $key = strtr(str_replace($buildDir.DIRECTORY_SEPARATOR, '', $file), '\\', '/');
                if (!in_array($key, $safeFiles, true)) {
                    unlink((string) $file);
                    if (file_exists($altDirFile = str_replace($buildDir, $oldBuildDir, (string) $file))) {
                        unlink($altDirFile);
                    }
                }
            }
        }

        // clean up old provider listings
        $finder = Finder::create()->depth(0)->files()->name('provider-*.json')->ignoreVCS(true)->in($buildDir)->date('until 10minutes ago');
        $providerFiles = array();
        foreach ($finder as $provider) {
            $key = strtr(str_replace($buildDir.DIRECTORY_SEPARATOR, '', $provider), '\\', '/');
            if (!in_array($key, $safeFiles, true)) {
                unlink((string) $provider);
                if (file_exists($altDirFile = str_replace($buildDir, $oldBuildDir, (string) $provider))) {
                    unlink($altDirFile);
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

        $this->writeFile($file, json_encode($this->rootFile));
    }

    private function dumpListing($path)
    {
        $key = basename($path);

        // sort files to make hash consistent
        ksort($this->listings[$key]['providers']);

        $json = json_encode($this->listings[$key]);
        $hash = hash('sha256', $json);
        $path = substr($path, 0, -5) . '$' . $hash . '.json';
        $this->writeFile($path, $json);

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

        $json = json_encode($this->individualFiles[$key]);
        $this->writeFile($path, $json, $this->individualFilesMtime[$key]);

        // write the hashed provider file
        $hashedFile = substr($path, 0, -5) . '$' . hash('sha256', $json) . '.json';
        $this->writeFile($hashedFile, $json);
    }

    private function dumpVersionToIndividualFile(Version $version, $file, $key)
    {
        $this->loadIndividualFile($file, $key);
        $data = $version->toArray();
        $data['uid'] = $version->getId();
        $this->individualFiles[$key]['packages'][strtolower($version->getName())][$version->getVersion()] = $data;
        if (!isset($this->individualFilesMtime[$key]) || $this->individualFilesMtime[$key] < $version->getReleasedAt()->getTimestamp()) {
            $this->individualFilesMtime[$key] = $version->getReleasedAt()->getTimestamp();
        }
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

    private function getTargetListing($file)
    {
        static $firstOfTheMonth;
        if (!$firstOfTheMonth) {
            $date = new \DateTime;
            $date->setDate($date->format('Y'), $date->format('m'), 1);
            $date->setTime(0, 0, 0);
            $firstOfTheMonth = $date->format('U');
        }

        $mtime = filemtime($file);

        if ($mtime < $firstOfTheMonth - 86400 * 180) {
            return 'provider-archived.json';
        }
        if ($mtime < $firstOfTheMonth - 86400 * 60) {
            return 'provider-stale.json';
        }
        if ($mtime < $firstOfTheMonth - 86400 * 10) {
            return 'provider-active.json';
        }

        return 'provider-latest.json';
    }

    private function writeFile($path, $contents, $mtime = null)
    {
        file_put_contents($path, $contents);
        if ($mtime !== null) {
            touch($path, $mtime);
        }

        if (is_array($this->writeLog)) {
            $this->writeLog[$path] = array($contents, $mtime);
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
