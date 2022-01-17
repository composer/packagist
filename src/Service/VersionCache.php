<?php declare(strict_types=1);

namespace App\Service;

use Composer\Pcre\Preg;
use Composer\Repository\VersionCacheInterface;
use App\Entity\Package;
use App\Entity\Version;

class VersionCache implements VersionCacheInterface
{
    /** @var array<string, array{version: string, normalizedVersion: string, source: array{reference: string|null, type: string|null, url: string|null}}> */
    private array $versionCache;

    public function __construct(
        private Package $package,
        /** @var array<string|int, array{version: string, normalizedVersion: string, source: array{reference: string|null, type: string|null, url: string|null}}> */
        private array $existingVersions,
        /** @var string[] */
        private array $emptyReferences
    ) {
        $this->versionCache = [];
        foreach ($existingVersions as $version) {
            $this->versionCache[$version['version']] = $version;
        }
        $this->package = $package;
        $this->emptyReferences = $emptyReferences;
    }

    /**
     * @param string $version
     * @param string $identifier
     * @return array{name: string, version: string, version_normalized: string, source: array{reference: string|null, type: string|null, url: string|null}}|false|null
     */
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

    public function clearVersion(string $version): void
    {
        foreach (array_keys($this->versionCache) as $v) {
            $v = (string) $v;
            if (Preg::replace('{\.x-dev$}', '', $v) === $version || Preg::replace('{-dev$}', '', $v) === $version || Preg::replace('{^dev-}', '', $v) === $version) {
                unset($this->versionCache[$v]);
            }
        }
    }
}
