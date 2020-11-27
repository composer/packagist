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

use FOS\UserBundle\Model\UserInterface;
use App\Entity\Package;
use App\Entity\PackageRepository;
use App\Entity\UserRepository;
use Predis\Client;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class FavoriteManager
{
    protected $redis;
    protected $packageRepo;
    protected $userRepo;

    public function __construct(Client $redis, PackageRepository $packageRepo, UserRepository $userRepo)
    {
        $this->redis = $redis;
        $this->packageRepo = $packageRepo;
        $this->userRepo = $userRepo;
    }

    public function markFavorite(UserInterface $user, Package $package)
    {
        if (!$this->isMarked($user, $package)) {
            $this->redis->zadd('pkg:'.$package->getId().':fav', time(), $user->getId());
            $this->redis->zadd('usr:'.$user->getId().':fav', time(), $package->getId());
        }
    }

    public function removeFavorite(UserInterface $user, Package $package)
    {
        $this->redis->zrem('pkg:'.$package->getId().':fav', $user->getId());
        $this->redis->zrem('usr:'.$user->getId().':fav', $package->getId());
    }

    public function getFavorites(UserInterface $user, $limit = 0, $offset = 0)
    {
        $favoriteIds = $this->redis->zrevrange('usr:'.$user->getId().':fav', $offset, $offset + $limit - 1);

        return $this->packageRepo->findById($favoriteIds);
    }

    public function getFavoriteCount(UserInterface $user)
    {
        return $this->redis->zcard('usr:'.$user->getId().':fav');
    }

    public function getFavers(Package $package, $offset = 0, $limit = 100)
    {
        $faverIds = $this->redis->zrevrange('pkg:'.$package->getId().':fav', $offset, $offset + $limit - 1);

        return $this->userRepo->findById($faverIds);
    }

    public function getFaverCount(Package $package)
    {
        return $this->redis->zcard('pkg:'.$package->getId().':fav') + $package->getGitHubStars();
    }

    public function getFaverCounts(array $packageIds)
    {
        $res = array();

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

    public function isMarked(UserInterface $user, Package $package)
    {
        return null !== $this->redis->zrank('usr:'.$user->getId().':fav', $package->getId());
    }
}
