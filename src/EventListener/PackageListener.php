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
use App\Entity\Package;
use App\Entity\User;
use App\Util\DoctrineTrait;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;

#[AsEntityListener(event: 'postPersist', entity: Package::class)]
#[AsEntityListener(event: 'preRemove', entity: Package::class)]
#[AsEntityListener(event: 'preUpdate', entity: Package::class)]
#[AsEntityListener(event: 'postUpdate', entity: Package::class)]
class PackageListener
{
    use DoctrineTrait;

    /** @var list<AuditRecord> */
    private array $buffered = [];

    public function __construct(
        private ManagerRegistry $doctrine,
        private Security $security,
    ) {
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function postPersist(Package $package, LifecycleEventArgs $event): void
    {
        $record = AuditRecord::packageCreated($package, $this->getUser());
        $this->getEM()->persist($record);
        $this->getEM()->flush();
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function preRemove(Package $package, LifecycleEventArgs $event): void
    {
        $record = AuditRecord::packageDeleted($package, $this->getUser());
        $this->getEM()->persist($record);
        // let the record be flushed together with the entity
    }

    public function preUpdate(Package $package, PreUpdateEventArgs $event): void
    {
        if ($event->hasChangedField('repository')) {
            // buffering things to be flushed in postUpdate as flushing here results in an infinite loop
            $this->buffered[] = AuditRecord::canonicalUrlChange($package, $this->getUser(), $event->getOldValue('repository'));
        }
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function postUpdate(Package $package, LifecycleEventArgs $event): void
    {
        if ($this->buffered) {
            foreach ($this->buffered as $record) {
                $this->getEM()->persist($record);
            }
            $this->getEM()->flush();
            $this->buffered = [];
        }
    }

    private function getUser(): User|null
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
