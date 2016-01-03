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

namespace Packagist\WebBundle\Model;

use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Predis\Client;

/**
 * Manages the download counts for packages.
 */
class DownloadManager
{
    protected $redis;
    protected $redisCommandLoaded = false;

    public function __construct(Client $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Gets the total, monthly, and daily download counts for an entire package or optionally a version.
     *
     * @param \Packagist\WebBundle\Entity\Package|int      $package
     * @param \Packagist\WebBundle\Entity\Version|int|null $version
     * @return array
     */
    public function getDownloads($package, $version = null)
    {
        if ($package instanceof Package) {
            $package = $package->getId();
        }

        if ($version instanceof Version) {
            $version = $version->getId();
        }

        if ($version !== null) {
            $version = '-'.$version;
        }

        $date = new \DateTime();
        $keys = array('dl:'.$package . $version);
        for ($i = 0; $i < 30; $i++) {
            $keys[] = 'dl:' . $package . $version . ':' . $date->format('Ymd');
            $date->modify('-1 day');
        }

        $vals = $this->redis->mget($keys);
        $result = array(
            'total' => (int) array_shift($vals) ?: 0,
            'monthly' => (int) array_sum($vals) ?: 0,
            'daily' => (int) $vals[0] ?: 0,
        );

        return $result;
    }

    /**
     * Gets the total download count for a package.
     *
     * @param \Packagist\WebBundle\Entity\Package|int $package
     * @return int
     */
    public function getTotalDownloads($package)
    {
        if ($package instanceof Package) {
            $package = $package->getId();
        }

        return (int) $this->redis->get('dl:' . $package) ?: 0;
    }

    /**
     * Gets total download counts for multiple package IDs.
     *
     * @param array $packageIds
     * @return array a map of package ID to download count
     */
    public function getPackagesDownloads(array $packageIds)
    {
        $keys = array();

        foreach ($packageIds as $id) {
            if (ctype_digit((string) $id)) {
                $keys[$id] = 'dl:'.$id;
            }
        }

        if (!$keys) {
            return array();
        }

        $res = array_map('intval', $this->redis->mget(array_values($keys)));
        return array_combine(array_keys($keys), $res);
    }

    /**
     * Tracks a new download by updating the relevant keys.
     *
     * @param \Packagist\WebBundle\Entity\Package|int $package
     * @param \Packagist\WebBundle\Entity\Version|int $version
     */
    public function addDownload($package, $version)
    {
        $redis = $this->redis;

        if (!$this->redisCommandLoaded) {
            $redis->getProfile()->defineCommand('downloadsIncr', 'Packagist\Redis\DownloadsIncr');
            $this->redisCommandLoaded = true;
        }

        if ($package instanceof Package) {
            $package = $package->getId();
        }

        if ($version instanceof Version) {
            $version = $version->getId();
        }

        $redis->downloadsIncr(
            'downloads',
            'dl:'.$package,
            'dl:'.$package.':'.date('Ym'),
            'dl:'.$package.':'.date('Ymd'),
            'dl:'.$package.'-'.$version,
            'dl:'.$package.'-'.$version.':'.date('Ym'),
            'dl:'.$package.'-'.$version.':'.date('Ymd')
        );
    }
}
