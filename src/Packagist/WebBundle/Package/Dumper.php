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
        if (!$force) {
            foreach (glob($webDir.'/packages*.json') as $file) {
                copy($file, $buildDir.'/'.basename($file));
            }
        }

        $modifiedFiles = array();

        while ($packageIds) {
            $packages = $this->doctrine->getRepository('PackagistWebBundle:Package')->getPackagesWithVersions(array_splice($packageIds, 0, 50));

            if ($verbose) {
                echo 'Processing '.count($packages).' packages...'.PHP_EOL;
            }

            // prepare packages in memory
            foreach ($packages as $package) {

                // clean up all versions of that package
                foreach (glob($buildDir.'/packages*.json') as $file) {
                    $key = basename($file);
                    $this->loadFile($file);
                    if (isset($this->files[$key]['packages'][$package->getName()])) {
                        unset($this->files[$key]['packages'][$package->getName()]);
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
        }

        // prepare root file
        $rootFile = $buildDir.'/packages_root.json';
        $this->loadFile($rootFile);
        if (!isset($this->files['packages_root.json']['packages'])) {
            $this->files['packages_root.json']['packages'] = array();
        }
        $url = $this->router->generate('track_download', array('name' => 'VND/PKG'));
        $this->files['packages_root.json']['notify'] = str_replace('VND/PKG', '%package%', $url);

        // dump files to build dir
        foreach ($modifiedFiles as $file => $dummy) {
            $this->dumpFile($buildDir.'/'.$file);
            $this->files['packages_root.json']['includes'][$file] = array('sha1' => sha1_file($buildDir.'/'.$file));
        }
        $this->dumpFile($rootFile);

        // put the new files in production
        foreach ($modifiedFiles as $file => $dummy) {
            rename($buildDir.'/'.$file, $webDir.'/'.$file);
        }
        rename($rootFile, $webDir.'/'.basename($rootFile));

        if ($force) {
            // clear files that were not created in this build
            foreach (glob($webDir.'/packages-*.json') as $file) {
                if (!isset($modifiedFiles[basename($file)])) {
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

    private function dumpVersion(Version $version, $file)
    {
        $this->loadFile($file);
        $this->files[basename($file)]['packages'][$version->getName()][$version->getVersion()] = $version->toArray();
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
}
