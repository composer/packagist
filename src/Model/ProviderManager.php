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
     * Resets the drift accumulated by writes that bypassed insertPackage()/deletePackage().
     *
     * @param string[] $names
     */
    public function rebuildPackageSet(array $names): void
    {
        $this->swapSet('set:packages', $names);
    }

    /**
     * Rebuilt by cron rather than lazily behind a TTL: getProvidedNames() runs long enough that
     * every request arriving while it ran started its own copy, 533k executions over five months
     * where 24 a day would have done. Nothing on a page render can reach the query now.
     *
     * @param string[] $names
     */
    public function rebuildProviderSet(array $names): void
    {
        $this->swapSet('set:providers', $names);
    }

    /**
     * Swapped rather than written in place so no reader observes a partial set, and so the scard()
     * guards that fall back to the DB cannot be tripped by a momentarily missing key.
     *
     * @param string[] $names
     */
    private function swapSet(string $key, array $names): void
    {
        // never let a failed query wipe the set every lookup reads
        if (\count($names) === 0) {
            throw new \RuntimeException('Refusing to rebuild '.$key.' from an empty name list');
        }

        $this->redis->del($key.':new');
        while ($names) {
            $nameSlice = array_splice($names, 0, 1000);
            $this->redis->sadd($key.':new', $nameSlice);
        }

        if (!(bool) $this->redis->exists($key)) {
            $this->redis->rename($key.':new', $key);

            return;
        }

        $this->redis->transaction(static function ($tx) use ($key): void {
            $tx->rename($key, $key.':old');
            $tx->rename($key.':new', $key);
        });

        // UNLINK frees the old members on a background thread; DEL, or RENAME's implicit
        // overwrite, would free them inline and stall the event loop
        $this->redis->unlink($key.':old');
    }
}
