<?php declare(strict_types=1);

namespace App\Service;

use Composer\Repository\VersionCacheInterface;
use App\Entity\Package;
use App\Entity\Version;

class VersionCache implements VersionCacheInterface
{
    /** @var Version[] */
    private $versionCache;
    private $emptyReferences;
    private $package;

    public function __construct(Package $package, array $existingVersions, array $emptyReferences)
    {
        $this->versionCache = [];
        foreach ($existingVersions as $version) {
            $this->versionCache[$version['version']] = $version;
        }
        $this->package = $package;
        $this->emptyReferences = $emptyReferences;
    }

    public function getVersionPackage($version, $identifier)
    {
        if (!empty($this->versionCache[$version]['source']['reference']) && $this->versionCache[$version]['source']['reference'] === $identifier) {
            return [
                'name' => $this->package->getName(),
                'version' => $this->versionCache[$version]['version'],
                'version_normalized' => $this->versionCache[$version]['normalizedVersion'],
                'source' => $this->versionCache[$version]['source'],
            ];
        }

        if (in_array($identifier, $this->emptyReferences, true)) {
            return false;
        }

        return null;
    }

    public function clearVersion($version)
    {
        foreach (array_keys($this->versionCache) as $v) {
            if (preg_replace('{\.x-dev$}', '', $v) === $version || preg_replace('{-dev$}', '', $v) === $version || preg_replace('{^dev-}', '', $v) === $version) {
                unset($this->versionCache[$v]);
            }
        }
    }
}
