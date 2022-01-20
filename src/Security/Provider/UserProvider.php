<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Security\Provider;

use App\Entity\UserRepository;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\User;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use App\Service\Scheduler;

class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    use DoctrineTrait;

    private ManagerRegistry $doctrine;

    private Scheduler $scheduler;

    public function __construct(ManagerRegistry $doctrine, Scheduler $scheduler)
    {
        $this->doctrine = $doctrine;
        $this->scheduler = $scheduler;
    }

    public function loadUserByIdentifier(string $usernameOrEmail): User
    {
        $user = $this->getRepo()->findOneByUsernameOrEmail((string) $usernameOrEmail);

        if (null === $user) {
            throw new UserNotFoundException();
        }

        return $user;
    }

    // TODO delete in Symfony6
    public function loadUserByUsername($usernameOrEmail): User
    {
        return $this->loadUserByIdentifier($usernameOrEmail);
    }

    /**
     * @inheritDoc
     */
    public function refreshUser(UserInterface $user): User
    {
        if (!$user instanceof User) {
            throw new \UnexpectedValueException('Expected '.User::class.', got '.get_class($user));
        }

        $user = $this->getRepo()->find($user->getId());
        if (null === $user) {
            throw new \RuntimeException('The user could not be reloaded as it does not exist anymore in the database');
        }

        return $user;
    }

    public function upgradePassword(UserInterface $user, string $newEncodedPassword): void
    {
        if (!$user instanceof User) {
            throw new \UnexpectedValueException('Expected '.User::class.', got '.get_class($user));
        }

        $user->setPassword($newEncodedPassword);
        $user->setSalt(null);

        $this->getEM()->persist($user);
        $this->getEM()->flush();
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }

    private function getRepo(): UserRepository
    {
        return $this->getEM()->getRepository(User::class);
    }
}
