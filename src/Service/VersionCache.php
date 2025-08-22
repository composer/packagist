<?php declare(strict_types=1);

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use App\Entity\Package;
use App\Entity\Version;
use Composer\Pcre\Preg;
use Composer\Repository\VersionCacheInterface;
use Composer\Semver\VersionParser;

class VersionCache implements VersionCacheInterface
{
    /** @var array<string, array{version: string, normalizedVersion: string, source: array{type: string|null, url: string|null, reference: string|null}|null}> */
    private array $versionCache = [];

    /**
     * @param array<string|int, array{version: string, normalizedVersion: string, source: array{type: string|null, url: string|null, reference: string|null}|null}> $existingVersions
     * @param string[]                                                                                                                                              $emptyReferences
     */
    public function __construct(
        private Package $package,
        array $existingVersions,
        private array $emptyReferences,
    ) {
        foreach ($existingVersions as $version) {
            $this->versionCache[$version['version']] = $version;
        }
    }

    /**
     * @return array{name: string, version: string, version_normalized: string, source: array{type: string|null, url: string|null, reference: string|null}|null}|false|null
     */
    public function getVersionPackage(string $version, string $identifier): array|false|null
    {
        if (!empty($this->versionCache[$version]['source']['reference']) && $this->versionCache[$version]['source']['reference'] === $identifier) {
            // if the source has some corrupted github private url we do not return a cached version to ensure full metadata gets loaded
            if (isset($this->versionCache[$version]['source']['url']) && is_string($this->versionCache[$version]['source']['url']) && Preg::isMatch('{^git@github.com:.*?\.git$}', $this->versionCache[$version]['source']['url'])) {
                return null;
            }

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
