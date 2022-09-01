<?php

namespace App\EventListener;

use App\Entity\Package;
use App\Entity\SecurityAdvisory;
use App\Util\DoctrineTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ManagerRegistry;

class SecurityAdvisoryUpdateListener
{
    use DoctrineTrait;

    /** @var array<string, true> */
    private $packagesToMarkStale = [];

    public function __construct(
        private ManagerRegistry $doctrine
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

    public function flushChangesToPackages(): void
    {
        $pkg = $this->getEM()->getConnection()->executeStatement(
            'UPDATE package SET dumpedAtV2 = null WHERE name IN (:names)',
            ['names' => array_keys($this->packagesToMarkStale)],
            ['names' => Connection::PARAM_STR_ARRAY]
        );

        $this->packagesToMarkStale = [];
    }

    private function dumpPackage(string $packageName): void
    {
        $this->packagesToMarkStale[$packageName] = true;
    }
}
