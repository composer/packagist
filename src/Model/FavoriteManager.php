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

namespace App\Model;

use App\Entity\Package;
use App\Entity\PackageRepository;
use App\Entity\User;
use App\Entity\UserRepository;
use Predis\Client;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class FavoriteManager
{
    public function __construct(
        private Client $redis,
        private PackageRepository $packageRepo,
        private UserRepository $userRepo
    ) {
    }

    public function markFavorite(User $user, Package $package): void
    {
        if (!$this->isMarked($user, $package)) {
            $this->redis->zadd('pkg:'.$package->getId().':fav', [$user->getId() => time()]);
            $this->redis->zadd('usr:'.$user->getId().':fav', [$package->getId() => time()]);
        }
    }

    public function removeFavorite(User $user, Package $package): void
    {
        $this->redis->zrem('pkg:'.$package->getId().':fav', $user->getId());
        $this->redis->zrem('usr:'.$user->getId().':fav', $package->getId());
    }

    /**
     * @return Package[]
     */
    public function getFavorites(User $user, int $limit = 0, int $offset = 0): array
    {
        $favoriteIds = $this->redis->zrevrange('usr:'.$user->getId().':fav', $offset, $offset + $limit - 1);

        return $this->packageRepo->findBy(['id' => $favoriteIds]);
    }

    public function getFavoriteCount(User $user): int
    {
        return $this->redis->zcard('usr:'.$user->getId().':fav');
    }

    /**
     * @return User[]
     */
    public function getFavers(Package $package, int $offset = 0, int $limit = 100): array
    {
        $faverIds = $this->redis->zrevrange('pkg:'.$package->getId().':fav', $offset, $offset + $limit - 1);

        return $this->userRepo->findBy(['id' => $faverIds]);
    }

    public function getFaverCount(Package $package): int
    {
        return $this->redis->zcard('pkg:'.$package->getId().':fav') + $package->getGitHubStars();
    }

    /**
     * @param array<int> $packageIds
     * @return array<int, int>
     */
    public function getFaverCounts(array $packageIds): array
    {
        $res = [];

        // TODO should be done with scripting when available
        foreach ($packageIds as $id) {
            if (ctype_digit((string) $id)) {
                $res[$id] = $this->redis->zcard('pkg:'.$id.':fav');
            }
        }

        $rows = $this->packageRepo->getGitHubStars($packageIds);
        foreach ($rows as $row) {
            $res[$row['id']] += $row['gitHubStars'];
        }

        return $res;
    }

    public function isMarked(User $user, Package $package): bool
    {
        return null !== $this->redis->zrank('usr:'.$user->getId().':fav', $package->getId());
    }
}
