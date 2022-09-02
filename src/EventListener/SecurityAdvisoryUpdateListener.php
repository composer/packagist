<?php

namespace App\EventListener;

use App\Entity\Package;
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
    private $packagesToMarkStale = [];

    public function __construct(
        private ManagerRegistry $doctrine,
        private Client $redisCache,
    ) {}

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

        $redisKeys = array_map(fn ($pkg) => 'sec-adv:'.$pkg, $packageNames);
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
