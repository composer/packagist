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

    #[AsEventListener]
    public function onVersionReferenceChanged(VersionReferenceChangedEvent $event): void
    {
        $version = $event->getVersion();

        if ($version->isDevelopment() && !$event->hasMetadataChanged()) {
            return;
        }

        $this->buffered[] = AuditRecord::versionReferenceChange(
            $version,
            $event->getOldSourceRef(),
            $event->getOldDistRef(),
            $event->getNewMetadata()
        );
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function postUpdate(Version $version, LifecycleEventArgs $event): void
    {
        if ($this->buffered === []) {
            return;
        }

        $repo = $this->getEM()->getRepository(AuditRecord::class);
        foreach ($this->buffered as $record) {
            $repo->insert($record);
        }

        $this->buffered = [];
    }

    private function getUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
