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

use App\Entity\AuditRecord;
use App\Entity\FilterListEntry;
use App\Entity\User;
use App\Util\DoctrineTrait;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;

#[AsEntityListener(event: 'postPersist', entity: FilterListEntry::class)]
#[AsEntityListener(event: 'preRemove', entity: FilterListEntry::class)]
class FilterListEntryListener
{
    use DoctrineTrait;

    public function __construct(
        private ManagerRegistry $doctrine,
        private Security $security,
    ) {}

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function postPersist(FilterListEntry $entry, LifecycleEventArgs $event): void
    {
        $this->getEM()->getRepository(AuditRecord::class)->insert(AuditRecord::filterListEntryAdded($entry, $this->getUser()));
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function preRemove(FilterListEntry $entry, LifecycleEventArgs $event): void
    {
        $this->getEM()->persist(AuditRecord::filterListEntryDeleted($entry, $this->getUser()));
    }

    private function getUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
