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

namespace App\EventListener;

use App\Entity\SecurityAdvisory;
use App\Util\DoctrineTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;

class SecurityAdvisoryUpdateListener
{
    use DoctrineTrait;

    /** @var array<string, true> */
    private array $packagesToMarkStale = [];

    public function __construct(
        private ManagerRegistry $doctrine,
        private Client $redisCache,
    ) {
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function postUpdate(SecurityAdvisory $advisory, LifecycleEventArgs $event): void
    {
        $this->dumpPackage($advisory->getPackageName());
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function postPersist(SecurityAdvisory $advisory, LifecycleEventArgs $event): void
    {
        $this->dumpPackage($advisory->getPackageName());
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function postRemove(SecurityAdvisory $advisory, LifecycleEventArgs $event): void
    {
        $this->dumpPackage($advisory->getPackageName());
    }

    public function flushChangesToPackages(): void
    {
        $packageNames = array_keys($this->packagesToMarkStale);
        $pkg = $this->getEM()->getConnection()->executeStatement(
            'UPDATE package SET dumpedAtV2 = null WHERE name IN (:names)',
            ['names' => $packageNames],
            ['names' => Connection::PARAM_STR_ARRAY]
        );

        $this->packagesToMarkStale = [];

        $redisKeys = array_map(static fn ($pkg) => 'sec-adv:'.$pkg, $packageNames);
        while (count($redisKeys) > 0) {
            $keys = array_splice($redisKeys, 0, 1000);
            $this->redisCache->del($keys);
        }
    }

    private function dumpPackage(string $packageName): void
    {
        $this->packagesToMarkStale[$packageName] = true;
    }
}
