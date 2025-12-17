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

use App\Audit\UserRegistrationMethod;
use App\Entity\AuditRecord;
use App\Entity\User;
use App\Util\DoctrineTrait;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;

#[AsEntityListener(event: 'postPersist', entity: User::class)]
#[AsEntityListener(event: 'preRemove', entity: User::class)]
#[AsEntityListener(event: 'postRemove', entity: User::class)]
class UserListener
{
    use DoctrineTrait;

    /** @var list<AuditRecord>  */
    private array $buffered = [];

    public function __construct(
        private ManagerRegistry $doctrine,
        private Security $security,
    ) {
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $event
     */
    public function postPersist(User $user, LifecycleEventArgs $event): void
    {
        $method = UserRegistrationMethod::REGISTRATION_FORM;

        if ($user->getGitHubId() !== null && $user->getGitHubToken() !== null) {
            $method = UserRegistrationMethod::OAUTH_GITHUB;
        }

        $this->getEM()->getRepository(AuditRecord::class)->insert(AuditRecord::userCreated($user, $method));
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $args
     */
    public function preRemove(User $user, LifecycleEventArgs $args): void
    {
        $this->buffered[] = AuditRecord::userDeleted($user, $this->getActor());
    }

    /**
     * @param LifecycleEventArgs<EntityManager> $args
     */
    public function postRemove(User $user, LifecycleEventArgs $args): void
    {
        $repo = $this->getEM()->getRepository(AuditRecord::class);

        foreach ($this->buffered as $record) {
            $repo->insert($record);
        }

        $this->buffered = [];
    }

    private function getActor(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
