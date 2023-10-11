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
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UlidType;
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
        $this->insert(AuditRecord::packageCreated($package, $this->getUser()));
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
            foreach ($this->buffered as $record) {
                $this->insert($record);
            }
            $this->buffered = [];
        }
    }

    private function getUser(): User|null
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    private function insert(AuditRecord $record): void
    {
        $this->getEM()->getConnection()->insert('audit_log', [
            'id' => $record->id,
            'datetime' => $record->datetime,
            'type' => $record->type->value,
            'attributes' => $record->attributes,
            'userId' => $record->userId,
            'vendor' => $record->vendor,
            'packageId' => $record->packageId,
        ], [
            'id' => UlidType::NAME,
            'datetime' => Types::DATETIME_IMMUTABLE,
            'attributes' => Types::JSON
        ]);
    }
}
