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

use App\Audit\AbandonmentReason;
use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\User;
use App\Event\PackageAbandonedEvent;
use App\Event\PackageUnabandonedEvent;
use App\Util\DoctrineTrait;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEntityListener(event: 'postPersist', entity: Package::class)]
#[AsEntityListener(event: 'preRemove', entity: Package::class)]
#[AsEntityListener(event: 'preUpdate', entity: Package::class)]
#[AsEntityListener(event: 'postUpdate', entity: Package::class)]
#[AsEventListener(event: PackageAbandonedEvent::class, method: 'onPackageAbandoned')]
#[AsEventListener(event: PackageUnabandonedEvent::class, method: 'onPackageUnabandoned')]
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
        $this->getEM()->getRepository(AuditRecord::class)->insert(AuditRecord::packageCreated($package, $this->getUser()));
    }

    public function onPackageAbandoned(PackageAbandonedEvent $event): void
    {
        $package = $event->getPackage();
        $this->buffered[] = AuditRecord::packageAbandoned($package, $this->getUser(), $package->getReplacementPackage(), $event->getReason());
    }

    public function onPackageUnabandoned(PackageUnabandonedEvent $event): void
    {
        $package = $event->getPackage();
        $this->buffered[] = AuditRecord::packageUnabandoned($package, $this->getUser());
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
            // buffering things to be inserted in postUpdate once we can confirm it is done
            $this->buffered[] = AuditRecord::canonicalUrlChange($package, $this->getUser(), $event->getOldValue('repository'));
        }
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function postUpdate(Package $package, LifecycleEventArgs $event): void
    {
        if ($this->buffered) {
            $repo = $this->getEM()->getRepository(AuditRecord::class);
            foreach ($this->buffered as $record) {
                $repo->insert($record);
            }
            $this->buffered = [];
        }
    }

    private function getUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
