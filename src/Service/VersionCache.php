<?php declare(strict_types=1);

namespace App\Service;

use Composer\Pcre\Preg;
use Composer\Repository\VersionCacheInterface;
use App\Entity\Package;
use App\Entity\Version;
use Composer\Semver\VersionParser;
use DateTimeInterface;

class VersionCache implements VersionCacheInterface
{
    /** @var array<string, array{version: string, normalizedVersion: string, source: array{type: string|null, url: string|null, reference: string|null}|null}> */
    private array $versionCache = [];

    /**
     * @param array<string|int, array{version: string, normalizedVersion: string, source: array{type: string|null, url: string|null, reference: string|null}|null}> $existingVersions
     * @param string[] $emptyReferences
     */
    public function __construct(
        private Package $package,
        array $existingVersions,
        private array $emptyReferences
    ) {
        foreach ($existingVersions as $version) {
            $this->versionCache[$version['version']] = $version;
        }
    }

    /**
     * @param string $version
     * @param string $identifier
     * @return array{name: string, version: string, version_normalized: string, source: array{type: string|null, url: string|null, reference: string|null}|null}|false|null
     */
    public function getVersionPackage($version, $identifier): array|false|null
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
        $parser = new VersionParser();
        // handle branch names like 3.x.x or 3.X to make sure they match the normalized 3.x-dev below
        $version = Preg::replace('{(\.x)+}i', '.x', $version);

        // handle main => dev-main, 3 => 3.x-dev and 3.x => 3.x-dev
        foreach (array_keys($this->versionCache) as $v) {
            $v = (string) $v;
            if (Preg::replace('{\.x-dev$}', '', $v) === $version || Preg::replace('{-dev$}', '', $v) === $version || Preg::replace('{^dev-}', '', $v) === $version) {
                unset($this->versionCache[$v]);
            }
        }
    }
}
