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
    private $individualFiles = array();

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
            foreach (glob($webDir.'/packages*.json') as $file) {
                copy($file, $buildDir.'/'.basename($file));
            }

            $this->fs->mirror($webDir.'/p/', $buildDir.'/p/', null, array('override' => true));
        }

        $modifiedFiles = array();
        $modifiedIndividualFiles = array();

        $total = count($packageIds);
        $current = 0;
        $step = 50;
        while ($packageIds) {
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
                    $files = json_decode(file_get_contents($buildDir.'/p/'.$name.'.files.json'));

                    foreach ($files as $file) {
                        $key = $this->getIndividualFileKey($buildDir.'/'.$file);
                        $this->loadIndividualFile($buildDir.'/'.$file);
                        if (isset($this->individualFiles[$key]['packages'][$name])) {
                            unset($this->individualFiles[$key]['packages'][$name]);
                            $modifiedIndividualFiles[$key] = true;
                        }
                    }
                }

                // (re)write versions in individual files
                foreach ($package->getVersions() as $version) {
                    foreach ($version->getNames() as $versionName) {
                        if (!preg_match('{^[A-Za-z0-9_-][A-Za-z0-9_.-]+/[A-Za-z0-9_-][A-Za-z0-9_.-]+?$}', $versionName) || strpos($versionName, '..')) {
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
                file_put_contents($buildDir.'/p/'.$name.'.files.json', json_encode(array_keys($affectedFiles)));
                $modifiedIndividualFiles['p/'.$name.'.files.json'] = true;

                // clean up all versions of that package
                foreach (glob($buildDir.'/packages*.json') as $file) {
                    $key = basename($file);
                    $this->loadFile($file);
                    if (isset($this->files[$key]['packages'][$name])) {
                        unset($this->files[$key]['packages'][$name]);
                        $modifiedFiles[$key] = true;
                    }
                }

                // (re)write versions
                foreach ($package->getVersions() as $version) {
                    $file = $buildDir.'/'.$this->getTargetFile($version);
                    $modifiedFiles[basename($file)] = true;
                    $this->dumpVersion($version, $file);
                }

                $package->setDumpedAt(new \DateTime);

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
                }

                $this->individualFiles = array();
            }
        }

        // prepare root file
        $rootFile = $buildDir.'/packages_root.json';
        $this->loadFile($rootFile);
        if (!isset($this->files['packages_root.json']['packages'])) {
            $this->files['packages_root.json']['packages'] = array();
        }
        $url = $this->router->generate('track_download', array('name' => 'VND/PKG'));
        $this->files['packages_root.json']['notify'] = str_replace('VND/PKG', '%package%', $url);
        $this->files['packages_root.json']['providers'] = '/p/%package%.json';

        if ($verbose) {
            echo 'Dumping complete files'.PHP_EOL;
        }

        // dump files to build dir
        foreach ($modifiedFiles as $file => $dummy) {
            $this->dumpFile($buildDir.'/'.$file);
            $this->files['packages_root.json']['includes'][$file] = array('sha1' => sha1_file($buildDir.'/'.$file));
        }
        $this->dumpFile($rootFile);

        if ($verbose) {
            echo 'Putting new files in production'.PHP_EOL;
        }

        // put the new files in production
        foreach ($modifiedFiles as $file => $dummy) {
            rename($buildDir.'/'.$file, $webDir.'/'.$file);
        }
        rename($rootFile, $webDir.'/'.basename($rootFile));

        // put new individual files in production
        foreach ($modifiedIndividualFiles as $file => $dummy) {
            $this->fs->mkdir(dirname($webDir.'/'.$file));
            rename($buildDir.'/'.$file, $webDir.'/'.$file);
        }

        if ($force) {
            if ($verbose) {
                echo 'Cleaning up outdated files'.PHP_EOL;
            }

            // clear files that were not created in this build
            foreach (glob($webDir.'/packages-*.json') as $file) {
                if (!isset($modifiedFiles[basename($file)])) {
                    unlink($file);
                }
            }

            $finder = Finder::create()
                ->files()
                ->ignoreVCS(true)
                ->name('*.json')
                ->in($webDir.'/p/');

            foreach ($finder as $file) {
                $key = $this->getIndividualFileKey(strtr($file, '\\', '/'));
                if (!isset($modifiedIndividualFiles[$key])) {
                    unlink($file);
                }
            }
        }
    }

    private function loadFile($file)
    {
        $key = basename($file);

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
        $key = basename($file);

        // sort all versions and packages to make sha1 consistent
        ksort($this->files[$key]['packages']);
        foreach ($this->files[$key]['packages'] as $package => $versions) {
            ksort($this->files[$key]['packages'][$package]);
        }

        file_put_contents($file, json_encode($this->files[$key]));
    }

    private function loadIndividualFile($path, $key)
    {
        if (isset($this->individualFiles[$key])) {
            return;
        }

        if (file_exists($path)) {
            $this->individualFiles[$key] = json_decode(file_get_contents($path), true);
        } else {
            $this->individualFiles[$key] = array();
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
    }

    private function dumpVersion(Version $version, $file)
    {
        $this->loadFile($file);
        $this->files[basename($file)]['packages'][$version->getName()][$version->getVersion()] = $version->toArray();
    }

    private function dumpVersionToIndividualFile(Version $version, $file, $key)
    {
        $this->loadIndividualFile($file, $key);
        $data = $version->toArray();
        $data['uid'] = $version->getId();
        $this->individualFiles[$key]['packages'][strtolower($version->getName())][$version->getVersion()] = $data;
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

    private function getIndividualFileKey($path)
    {
        return preg_replace('{^.*?[/\\\\](p[/\\\\].+?(?:\.files)?\.json)$}', '$1', $path);
    }
}
