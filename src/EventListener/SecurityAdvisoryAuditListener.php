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
use App\Entity\SecurityAdvisory;
use App\Entity\User;
use App\Util\DoctrineTrait;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Records transparency log entries for the security advisory lifecycle.
 */
#[AsEntityListener(event: 'postPersist', entity: SecurityAdvisory::class)]
#[AsEntityListener(event: 'preUpdate', entity: SecurityAdvisory::class)]
#[AsEntityListener(event: 'postUpdate', entity: SecurityAdvisory::class)]
#[AsEntityListener(event: 'preRemove', entity: SecurityAdvisory::class)]
class SecurityAdvisoryAuditListener
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
    public function postPersist(SecurityAdvisory $advisory, LifecycleEventArgs $event): void
    {
        $this->getEM()->getRepository(AuditRecord::class)->insert(AuditRecord::securityAdvisoryCreated($advisory, $this->getUser()));
    }

    public function preUpdate(SecurityAdvisory $advisory, PreUpdateEventArgs $event): void
    {
        $this->buffered[] = AuditRecord::securityAdvisoryEdited($advisory, $this->getUser(), $event->getEntityChangeSet());
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function postUpdate(SecurityAdvisory $advisory, LifecycleEventArgs $event): void
    {
        if (!$this->buffered) {
            return;
        }

        $repository = $this->getEM()->getRepository(AuditRecord::class);
        foreach ($this->buffered as $record) {
            $repository->insert($record);
        }
        $this->buffered = [];
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function preRemove(SecurityAdvisory $advisory, LifecycleEventArgs $event): void
    {
        $this->getEM()->persist(AuditRecord::securityAdvisoryWithdrawn($advisory, $this->getUser()));
    }

    private function getUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
