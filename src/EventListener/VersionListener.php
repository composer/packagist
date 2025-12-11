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
use App\Event\VersionReferenceChangedEvent;
use App\Util\DoctrineTrait;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEntityListener(event: 'postPersist', entity: Version::class)]
#[AsEventListener(event: VersionReferenceChangedEvent::class, method: 'onVersionReferenceChanged')]
class VersionListener
{
    use DoctrineTrait;

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

    public function onVersionReferenceChanged(VersionReferenceChangedEvent $event): void
    {
        $version = $event->getVersion();

        if ($version->isDevelopment() && !$event->hasMetadataChanged()) {
            return;
        }

        $record = AuditRecord::versionReferenceChange(
            $version,
            $event->getOldSourceRef(),
            $event->getOldDistRef(),
            $event->getNewMetadata()
        );

        $this->getEM()->getRepository(AuditRecord::class)->insert($record);
    }

    private function getUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
