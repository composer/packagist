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
use App\Entity\User;
use App\Entity\Version;
use App\Util\DoctrineTrait;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;

#[AsEntityListener(event: 'preRemove', entity: Version::class)]
#[AsEntityListener(event: 'preUpdate', entity: Version::class)]
#[AsEntityListener(event: 'postPersist', entity: Version::class)]
#[AsEntityListener(event: 'postUpdate', entity: Version::class)]
class VersionListener
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
    public function postPersist(Version $version, LifecycleEventArgs $event): void
    {
        $data = $version->toV2Array([]);
        $record = AuditRecord::versionCreated($version, $data, $this->getUser());
        $this->getEM()->getRepository(AuditRecord::class)->insert($record);
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function preRemove(Version $version, LifecycleEventArgs $event): void
    {
        $record = AuditRecord::versionDeleted($version, $this->getUser());
        $this->getEM()->persist($record);
        // let the record be flushed together with the entity
    }

    public function preUpdate(Version $version, PreUpdateEventArgs $event): void
    {
        if (($event->hasChangedField('source') || $event->hasChangedField('dist')) && !$version->isDevelopment()) {
            $oldDistRef = $event->hasChangedField('dist') ? ($event->getOldValue('dist')['reference'] ?? null) : $version->getDist()['reference'] ?? null;
            $oldSourceRef = $event->hasChangedField('source') ? ($event->getOldValue('source')['reference'] ?? null) : $version->getSource()['reference'] ?? null;
            $newDistRef = $event->hasChangedField('dist') ? ($event->getNewValue('dist')['reference'] ?? null) : $version->getDist()['reference'] ?? null;
            $newSourceRef = $event->hasChangedField('source') ? ($event->getNewValue('source')['reference'] ?? null) : $version->getSource()['reference'] ?? null;
            if ($oldDistRef !== $newDistRef || $oldSourceRef !== $newSourceRef) {
                // buffering things to be inserted in postUpdate once we can confirm it is done
                $this->buffered[] = AuditRecord::versionReferenceChange($version, $oldSourceRef, $oldDistRef);
            }
        }
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function postUpdate(Version $version, LifecycleEventArgs $event): void
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
