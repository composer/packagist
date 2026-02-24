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

namespace App\Model;

use App\Entity\Package;
use App\Entity\Version;
use Predis\Client;

class VersionIdCache
{
    public function __construct(private Client $redis)
    {
    }

    /**
     * @param array<int, array{name: string, version: string, downloaded?: '?'|int|false}> $payload
     *
     * @return array<int, array{name: string, version: string, downloaded?: '?'|int|false, id?: int, vid?: int}>
     */
    public function augmentDownloadPayloadWithIds(array $payload): array
    {
        $args = [];
        foreach ($payload as $package) {
            $args[] = 'ids:'.strtolower($package['name']);
            $args[] = strtolower($package['version']);
        }
        /** @phpstan-ignore-next-line method.notFound */
        $results = $this->redis->fetchVersionIds(...$args);

        foreach ($results as $key => $result) {
            if ($result) {
                assert(isset($payload[$key]));
                [$id, $vid] = explode(',', $result);
                $payload[$key]['id'] = (int) $id;
                $payload[$key]['vid'] = (int) $vid;
            }
        }

        return $payload;
    }

    public function insertVersion(Package $package, Version $version): void
    {
        $this->redis->hset('ids:'.strtolower($package->getName()), strtolower($version->getNormalizedVersion()), $package->getId().','.$version->getId());
    }

    public function insertVersionRaw(int $packageId, string $name, int $versionId, string $versionNormalized): void
    {
        $this->redis->hset('ids:'.strtolower($name), strtolower($versionNormalized), $packageId.','.$versionId);
    }

    public function deleteVersion(Package $package, Version $version): void
    {
        $this->redis->hdel('ids:'.strtolower($package->getName()), [$version->getNormalizedVersion()]);
    }

    public function deletePackage(Package $package): void
    {
        $this->redis->del('ids:'.strtolower($package->getName()));
    }
}
