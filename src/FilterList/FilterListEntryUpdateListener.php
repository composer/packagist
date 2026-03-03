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

namespace App\FilterList;

use App\Entity\FilterListEntry;
use App\Util\DoctrineTrait;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ManagerRegistry;

#[AsEntityListener(event: 'postUpdate', entity: FilterListEntry::class)]
#[AsEntityListener(event: 'postPersist', entity: FilterListEntry::class)]
#[AsEntityListener(event: 'postRemove', entity: FilterListEntry::class)]
class FilterListEntryUpdateListener
{
    use DoctrineTrait;

    /** @var array<string, true> */
    private array $packagesToMarkStale = [];

    public function __construct(
        private ManagerRegistry $doctrine,
    ) {}

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function postUpdate(FilterListEntry $entry, LifecycleEventArgs $event): void
    {
        $this->dumpPackage($entry->getPackageName());
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function postPersist(FilterListEntry $entry, LifecycleEventArgs $event): void
    {
        $this->dumpPackage($entry->getPackageName());
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function postRemove(FilterListEntry $entry, LifecycleEventArgs $event): void
    {
        $this->dumpPackage($entry->getPackageName());
    }

    public function flushChangesToPackages(): void
    {
        if (count($this->packagesToMarkStale) === 0) {
            return;
        }

        $packageNames = array_keys($this->packagesToMarkStale);
        $this->getEM()->getConnection()->executeStatement(
            'UPDATE package SET dumpedAtV2 = null WHERE name IN (:names)',
            ['names' => $packageNames],
            ['names' => ArrayParameterType::STRING]
        );

        $this->packagesToMarkStale = [];
    }

    private function dumpPackage(string $packageName): void
    {
        $this->packagesToMarkStale[$packageName] = true;
    }
}
