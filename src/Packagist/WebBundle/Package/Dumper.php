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
class Dumper
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
     * Constructor
     *
     * @param RegistryInterface     $doctrine
     * @param Filesystem            $filesystem
     * @param UrlGeneratorInterface $router
     * @param string                $webDir     web root
     * @param string                $cacheDir   cache dir
     */
    public function __construct(RegistryInterface $doctrine, Filesystem $filesystem, UrlGeneratorInterface $router, $webDir, $cacheDir)
    {
        $this->doctrine = $doctrine;
        $this->fs = $filesystem;
        $this->cfs = new ComposerFilesystem;
        $this->router = $router;
        $this->webDir = realpath($webDir);
        $this->buildDir = $cacheDir . '/composer-packages-build';
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
        $buildDir = $this->buildDir;
        $retries = 5;
        do {
            if (!$this->cfs->removeDirectory($buildDir)) {
                usleep(200);
            }
            clearstatcache();
        } while (is_dir($buildDir) && $retries--);
        if (is_dir($buildDir)) {
            echo 'Could not remove the build dir entirely, aborting';

            return false;
        }
        $this->fs->mkdir($buildDir);
        $this->fs->mkdir($webDir.'/p/');

        if (!$force) {
            if ($verbose) {
                echo 'Copying existing files'.PHP_EOL;
            }

            exec('cp -rpf '.escapeshellarg($webDir.'/p').' '.escapeshellarg($buildDir.'/p'), $output, $exit);
            if (0 !== $exit) {
                $this->fs->mirror($webDir.'/p/', $buildDir.'/p/', null, array('override' => true));
            }
        }

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
                if (file_exists($buildDir.'/p/'.$name.'.files')) {
                    $files = json_decode(file_get_contents($buildDir.'/p/'.$name.'.files'));

                    foreach ($files as $file) {
                        $key = $this->getIndividualFileKey($buildDir.'/'.$file);
                        $this->loadIndividualFile($buildDir.'/'.$file, $key);
                        if (isset($this->individualFiles[$key]['packages'][$name])) {
                            unset($this->individualFiles[$key]['packages'][$name]);
                            $modifiedIndividualFiles[$key] = true;
                        }
                    }
                }

                // (re)write versions in individual files
                foreach ($package->getVersions() as $version) {
                    foreach (array_slice($version->getNames(), 0, 150) as $versionName) {
                        if (!preg_match('{^[A-Za-z0-9_-][A-Za-z0-9_.-]*/[A-Za-z0-9_-][A-Za-z0-9_.-]*$}', $versionName) || strpos($versionName, '..')) {
                            continue;
                        }

                        $file = $buildDir.'/p/'.$versionName.'.json';
                        $key = $this->getIndividualFileKey($file);
                        $this->dumpVersionToIndividualFile($version, $file, $key);
                        $modifiedIndividualFiles[$key] = true;
                        $affectedFiles[$key] = true;
                    }
                }

                // store affected files to clean up properly in the next update
                $this->fs->mkdir(dirname($buildDir.'/p/'.$name));
                file_put_contents($buildDir.'/p/'.$name.'.files', json_encode(array_keys($affectedFiles)));
                $modifiedIndividualFiles['p/'.$name.'.files'] = true;

                $package->setDumpedAt($dumpTime);
            }

            // update dump dates
            $this->doctrine->getManager()->flush();
            $this->doctrine->getManager()->clear();
            unset($packages);

            if ($current % 250 === 0 || !$packageIds) {
                if ($verbose) {
                    echo 'Dumping individual files'.PHP_EOL;
                }

                // dump individual files to build dir
                foreach ($this->individualFiles as $file => $dummy) {
                    $this->dumpIndividualFile($buildDir.'/'.$file, $file);

                    // write the hashed provider file
                    $hash = hash_file('sha256', $buildDir.'/'.$file);
                    $hashedFile = substr($buildDir.'/'.$file, 0, -5) . '$' . $hash . '.json';
                    copy($buildDir.'/'.$file, $hashedFile);
                }

                $this->individualFiles = array();
            }
        }

        // prepare individual files listings
        if ($verbose) {
            echo 'Preparing individual files listings'.PHP_EOL;
        }
        $safeFiles = array();
        $individualHashedListings = array();
        $finder = Finder::create()->files()->ignoreVCS(true)->name('*.json')->in($buildDir.'/p/')->depth('1');

        foreach ($finder as $file) {
            // skipped hashed files
            if (strpos($file, '$')) {
                continue;
            }

            $key = $this->getIndividualFileKey(strtr($file, '\\', '/'));
            if ($force && !isset($modifiedIndividualFiles[$key])) {
                continue;
            }

            // add hashed provider to listing
            $listing = 'p/'.$this->getTargetListing($file);
            $key = substr($key, 2, -5);
            $hash = hash_file('sha256', $file);
            $safeFiles[] = 'p/'.$key.'$'.$hash.'.json';
            $this->listings[$listing]['providers'][$key] = array('sha256' => $hash);
            $individualHashedListings[$listing] = true;
        }

        // prepare root file
        $rootFile = $buildDir.'/p/packages.json';
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
            $this->dumpListing($buildDir.'/'.$listing);
            $hash = hash_file('sha256', $buildDir.'/'.$listing);
            $hashedListing = substr($listing, 0, -5) . '$' . $hash . '.json';
            rename($buildDir.'/'.$listing, $buildDir.'/'.$hashedListing);
            $this->rootFile['provider-includes'][str_replace($hash, '%hash%', $hashedListing)] = array('sha256' => $hash);
            $safeFiles[] = $hashedListing;
        }

        if ($verbose) {
            echo 'Dumping root'.PHP_EOL;
        }

        // sort & dump root file
        ksort($this->rootFile['packages']);
        ksort($this->rootFile['provider-includes']);
        $this->dumpRootFile($rootFile);

        if ($verbose) {
            echo 'Putting new files in production'.PHP_EOL;
        }

        // put the new files in production
        exec(sprintf('mv %s %s && mv %s %1$s', escapeshellarg($webDir.'/p'), escapeshellarg($webDir.'/p-old'), escapeshellarg($buildDir.'/p')), $out, $exit);
        if (0 !== $exit) {
            throw new \RuntimeException("Rename failed:\n\n".implode("\n", $out));
        }

        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            rename($webDir.'/p/packages.json', $webDir.'/packages.json');
        } else {
            $packagesJsonPath = $webDir.'/packages.json';
            if (!is_link($packagesJsonPath)) {
                if (file_exists($packagesJsonPath)) {
                    unlink($packagesJsonPath);
                }
                symlink($webDir.'/p/packages.json', $webDir.'/packages.json');
            }
        }

        // clean up old dir
        $retries = 5;
        do {
            if (!$this->cfs->removeDirectory($webDir.'/p-old')) {
                usleep(200);
            }
            clearstatcache();
        } while (is_dir($webDir.'/p-old') && $retries--);

        // run only once an hour
        if ($cleanUpOldFiles) {
            if ($verbose) {
                echo 'Cleaning up old files'.PHP_EOL;
            }

            // clean up old files
            $finder = Finder::create()->directories()->ignoreVCS(true)->in($webDir.'/p/');
            foreach ($finder as $vendorDir) {
                $vendorFiles = Finder::create()->files()->ignoreVCS(true)
                    ->name('/\$[a-f0-9]+\.json$/')
                    ->date('until 10minutes ago')
                    ->in((string) $vendorDir);

                $hashedFiles = iterator_to_array($vendorFiles->getIterator());
                foreach ($hashedFiles as $file) {
                    $key = preg_replace('{(?:.*/|^)(p/[^/]+/[^/$]+\$[a-f0-9]+\.json)$}', '$1', strtr($file, '\\', '/'));
                    if (!in_array($key, $safeFiles, true)) {
                        unlink((string) $file);
                    }
                }
            }

            // clean up old provider listings
            $finder = Finder::create()->depth(0)->files()->name('provider-*.json')->ignoreVCS(true)->in($webDir.'/p/')->date('until 10minutes ago');
            $providerFiles = array();
            foreach ($finder as $provider) {
                $key = preg_replace('{(?:.*/|^)(p/[^/$]+\$[a-f0-9]+\.json)$}', '$1', strtr($provider, '\\', '/'));
                if (!in_array($key, $safeFiles, true)) {
                    unlink((string) $provider);
                }
            }
        }

        return true;
    }

    private function dumpRootFile($file)
    {
        // sort all versions and packages to make sha1 consistent
        ksort($this->rootFile['packages']);
        foreach ($this->rootFile['packages'] as $package => $versions) {
            ksort($this->rootFile['packages'][$package]);
        }

        file_put_contents($file, json_encode($this->rootFile));
    }

    private function dumpListing($listing)
    {
        $key = 'p/'.basename($listing);

        // sort files to make hash consistent
        ksort($this->listings[$key]['providers']);

        file_put_contents($listing, json_encode($this->listings[$key]));
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

    private function dumpIndividualFile($path, $key)
    {
        // sort all versions and packages to make sha1 consistent
        ksort($this->individualFiles[$key]['packages']);
        foreach ($this->individualFiles[$key]['packages'] as $package => $versions) {
            ksort($this->individualFiles[$key]['packages'][$package]);
        }

        $this->fs->mkdir(dirname($path));

        file_put_contents($path, json_encode($this->individualFiles[$key]));
        touch($path, $this->individualFilesMtime[$key]);
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

    private function getTargetFile(Version $version)
    {
        if ($version->isDevelopment()) {
            $distribution = 16;

            return 'packages-dev-' . chr(abs(crc32($version->getName())) % $distribution + 97) . '.json';
        }

        $date = $version->getReleasedAt();

        return 'packages-' . ($date->format('Y') === date('Y') ? $date->format('Y-m') : $date->format('Y')) . '.json';
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

    private function getIndividualFileKey($path)
    {
        return preg_replace('{^.*?[/\\\\](p[/\\\\].+?\.(json|files))$}', '$1', $path);
    }
}
