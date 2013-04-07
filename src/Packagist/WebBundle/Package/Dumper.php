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
    private $files = array();

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
     * @param RegistryInterface $doctrine
     * @param Filesystem $filesystem
     * @param UrlGeneratorInterface $router
     * @param string $webDir web root
     * @param string $cacheDir cache dir
     */
    public function __construct(RegistryInterface $doctrine, Filesystem $filesystem, UrlGeneratorInterface $router, $webDir, $cacheDir)
    {
        $this->doctrine = $doctrine;
        $this->fs = $filesystem;
        $this->router = $router;
        $this->webDir = realpath($webDir);
        $this->buildDir = $cacheDir . '/composer-packages-build';
    }

    /**
     * Dump a set of packages to the web root
     *
     * @param array $packageIds
     * @param Boolean $force
     * @param Boolean $verbose
     */
    public function dump(array $packageIds, $force = false, $verbose = false)
    {
        // prepare build dir
        $webDir = $this->webDir;
        $buildDir = $this->buildDir;
        $this->fs->remove($buildDir);
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

        $modifiedFiles = array();
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

                // clean up all versions of that package
                foreach (glob($buildDir.'/p/packages*.json') as $file) {
                    $key = 'p/'.basename($file);
                    $this->loadFile($file);
                    if (isset($this->files[$key]['packages'][$name])) {
                        unset($this->files[$key]['packages'][$name]);
                        $modifiedFiles[$key] = true;
                    }
                }

                // (re)write versions
                foreach ($package->getVersions() as $version) {
                    $file = $buildDir.'/p/'.$this->getTargetFile($version);
                    $modifiedFiles['p/'.basename($file)] = true;
                    $this->dumpVersion($version, $file);
                }

                $package->setDumpedAt($dumpTime);
            }

            // update dump dates
            $this->doctrine->getEntityManager()->flush();
            $this->doctrine->getEntityManager()->clear();
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
        $individualListings = array();
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

            $listing = 'p/'.$this->getTargetListing($file);
            $hash = hash_file('sha256', $file);
            $this->listings[$listing]['providers'][$key] = array('sha256' => $hash);
            $individualListings[$listing] = true;

            // add hashed provider to listing
            $listing = str_replace('providers', 'provider', $listing);
            $key = substr($key, 2, -5);
            $this->listings[$listing]['providers'][$key] = array('sha256' => $hash);
            $individualHashedListings[$listing] = true;
        }

        // prepare root file
        $rootFile = $buildDir.'/p/packages.json';
        $this->loadFile($rootFile);
        if (!isset($this->files['p/packages.json']['packages'])) {
            $this->files['p/packages.json']['packages'] = array();
        }
        $url = $this->router->generate('track_download', array('name' => 'VND/PKG'));
        $this->files['p/packages.json']['notify'] = str_replace('VND/PKG', '%package%', $url);
        $this->files['p/packages.json']['notify-batch'] = $this->router->generate('track_download_batch');
        $this->files['p/packages.json']['providers-url'] = $this->router->generate('home') . 'p/%package%$%hash%.json';
        $this->files['p/packages.json']['search'] = $this->router->generate('search', array('_format' => 'json')) . '?q=%query%';

        // TODO deprecated, remove eventually, together with includes & providers-includes
        $this->files['p/packages.json']['notify_batch'] = $this->files['p/packages.json']['notify-batch'];

        if ($verbose) {
            echo 'Dumping individual listings'.PHP_EOL;
        }

        // dump listings to build dir
        foreach ($individualListings as $listing => $dummy) {
            $this->dumpListing($buildDir.'/'.$listing);
            $hash = hash_file('sha256', $buildDir.'/'.$listing);
            $this->files['p/packages.json']['providers-includes'][$listing] = array('sha256' => $hash);
        }

        foreach ($individualHashedListings as $listing => $dummy) {
            $this->dumpListing($buildDir.'/'.$listing);
            $hash = hash_file('sha256', $buildDir.'/'.$listing);
            $hashedListing = substr($listing, 0, -5) . '$' . $hash . '.json';
            rename($buildDir.'/'.$listing, $buildDir.'/'.$hashedListing);
            $this->files['p/packages.json']['provider-includes'][str_replace($hash, '%hash%', $hashedListing)] = array('sha256' => $hash);
        }

        if ($verbose) {
            echo 'Dumping package metadata'.PHP_EOL;
        }

        // dump files to build dir
        foreach ($modifiedFiles as $file => $dummy) {
            $this->dumpFile($buildDir.'/'.$file);
            $this->files['p/packages.json']['includes'][$file] = array('sha1' => sha1_file($buildDir.'/'.$file));
        }

        if ($verbose) {
            echo 'Dumping root'.PHP_EOL;
        }

        // sort & dump root file
        ksort($this->files['p/packages.json']['packages']);
        ksort($this->files['p/packages.json']['providers-includes']);
        ksort($this->files['p/packages.json']['provider-includes']);
        ksort($this->files['p/packages.json']['includes']);
        $this->dumpFile($rootFile);

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
            if (!is_link($webDir.'/packages.json')) {
                unlink($webDir.'/packages.json');
                symlink($webDir.'/p/packages.json', $webDir.'/packages.json');
            }
        }

        // clean up old dir
        $retries = 5;
        do {
            exec(sprintf('rm -rf %s', escapeshellarg($webDir.'/p-old')), $output);
            usleep(200);
            clearstatcache();
        } while (is_dir($webDir.'/p-old') && $retries--);

        if ($verbose) {
            echo 'Cleaning up old files'.PHP_EOL;
        }

        // run only once an hour
        if (date('i') == 0) {
            // clean up old files
            $finder = Finder::create()->directories()->ignoreVCS(true)->in($webDir.'/p/');
            foreach ($finder as $vendorDir) {
                $vendorFiles = Finder::create()->files()->ignoreVCS(true)
                    ->name('/\$[a-f0-9]+\.json$/')
                    ->date('until 10minutes ago')
                    ->in((string) $vendorDir);

                $hashedFiles = iterator_to_array($vendorFiles->getIterator());
                $hashedByPackage = array();
                foreach ($hashedFiles as $file) {
                    $package = preg_replace('{.+/([^/$]+?)\$[a-f0-9]+\.json$}', '$1', strtr($file, '\\', '/'));
                    $hashedByPackage[$package][] = (string) $file;
                }

                foreach ($hashedByPackage as $package => $files) {
                    // if multiple hashed files exist for one package, remove all but the newest one
                    if (count($files) > 1) {
                        $orderedFiles = array();
                        foreach ($files as $file) {
                            $orderedFiles[$file] = filemtime($file);
                        }
                        asort($orderedFiles);
                        array_pop($orderedFiles);
                        foreach ($orderedFiles as $file => $mtime) {
                            unlink($file);
                        }
                    }
                }
            }

            // clean up old provider listings
            $finder = Finder::create()->depth(0)->files()->name('provider-*.json')->ignoreVCS(true)->in($webDir.'/p/')->date('until 1hour ago');
            foreach ($finder as $provider) {
                unlink($provider);
            }
        }
    }

    private function loadFile($file)
    {
        $key = 'p/'.basename($file);

        if (isset($this->files[$key])) {
            return;
        }

        if (file_exists($file)) {
            $this->files[$key] = json_decode(file_get_contents($file), true);
        } else {
            $this->files[$key] = array();
        }
    }

    private function dumpFile($file)
    {
        $key = 'p/'.basename($file);

        // sort all versions and packages to make sha1 consistent
        ksort($this->files[$key]['packages']);
        foreach ($this->files[$key]['packages'] as $package => $versions) {
            ksort($this->files[$key]['packages'][$package]);
        }

        file_put_contents($file, json_encode($this->files[$key]));
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

    private function dumpVersion(Version $version, $file)
    {
        $this->loadFile($file);
        $this->files['p/'.basename($file)]['packages'][$version->getName()][$version->getVersion()] = $version->toArray();
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
            return 'providers-archived.json';
        }
        if ($mtime < $firstOfTheMonth - 86400 * 60) {
            return 'providers-stale.json';
        }
        if ($mtime < $firstOfTheMonth - 86400 * 10) {
            return 'providers-active.json';
        }

        return 'providers-latest.json';
    }

    private function getIndividualFileKey($path)
    {
        return preg_replace('{^.*?[/\\\\](p[/\\\\].+?\.(json|files))$}', '$1', $path);
    }
}
