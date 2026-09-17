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
use App\Entity\PackageRepository;
use App\Package\PackageListCache;
use Predis\Client;

class ProviderManager
{
    protected bool $initializedProviders = false;

    public function __construct(private Client $redis, private PackageRepository $repo, private PackageListCache $listCache)
    {
    }

    public function packageExists(string $name): bool
    {
        return (bool) $this->redis->sismember('set:packages', strtolower($name));
    }

    /**
     * Check if multiple packages exist in the registry
     *
     * @param string[] $names Package names to check
     *
     * @return array<string, bool> Associative array of package name => exists
     */
    public function packagesExist(array $names): array
    {
        if (0 === \count($names)) {
            return [];
        }

        $names = array_map('strtolower', $names);
        /** @phpstan-ignore-next-line method.notFound */
        $results = $this->redis->packagesExist(...$names);

        $exists = [];
        foreach ($results as $i => $result) {
            $exists[$names[$i]] = (bool) $result;
        }

        return $exists;
    }

    public function packageIsProvided(string $name): bool
    {
        if (false === $this->initializedProviders) {
            if (!$this->redis->scard('set:providers')) {
                $this->populateProviders();
            }
            $this->initializedProviders = true;
        }

        return (bool) $this->redis->sismember('set:providers', strtolower($name));
    }

    /**
     * @return string[]
     */
    public function getPackageNames(): array
    {
        if (!$this->redis->scard('set:packages')) {
            $names = $this->repo->getPackageNames();
            while ($names) {
                $nameSlice = array_splice($names, 0, 1000);
                $this->redis->sadd('set:packages', $nameSlice);
            }
        }

        $names = $this->redis->smembers('set:packages');
        sort($names, \SORT_STRING | \SORT_FLAG_CASE);

        return $names;
    }

    public function insertPackage(Package $package): void
    {
        $this->redis->sadd('set:packages', [strtolower($package->getName())]);
        $this->listCache->markStale();
    }

    public function deletePackage(Package $package): void
    {
        $this->redis->srem('set:packages', strtolower($package->getName()));
        $this->listCache->markStale();
    }

    /**
     * Swaps in a set rebuilt from the DB, to reset any drift accumulated by writes that bypassed
     * insertPackage()/deletePackage(). Swapped atomically so packageExists() never observes a
     * partial set, and so getPackageNames()'s scard() guard cannot be tripped into a full DB
     * repopulate by a momentarily missing key.
     *
     * @param string[] $names
     */
    public function rebuildPackageSet(array $names): void
    {
        // never let a failed query wipe the set every packageExists() check reads
        if (\count($names) === 0) {
            throw new \RuntimeException('Refusing to rebuild set:packages from an empty name list');
        }

        $this->redis->del('set:packages:new');
        while ($names) {
            $nameSlice = array_splice($names, 0, 1000);
            $this->redis->sadd('set:packages:new', $nameSlice);
        }

        if (!(bool) $this->redis->exists('set:packages')) {
            $this->redis->rename('set:packages:new', 'set:packages');

            return;
        }

        $this->redis->transaction(static function ($tx): void {
            $tx->rename('set:packages', 'set:packages:old');
            $tx->rename('set:packages:new', 'set:packages');
        });

        // UNLINK frees the old members on a background thread; DEL, or RENAME's implicit
        // overwrite, would free them inline and stall the event loop
        $this->redis->unlink('set:packages:old');
    }

    private function populateProviders(): void
    {
        $names = $this->repo->getProvidedNames();
        while ($names) {
            $nameSlice = array_splice($names, 0, 1000);
            $this->redis->sadd('set:providers', $nameSlice);
        }

        $this->redis->expire('set:providers', 3600);
    }
}
