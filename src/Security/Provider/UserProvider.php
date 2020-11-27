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

use FOS\UserBundle\Model\UserManagerInterface;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use HWI\Bundle\OAuthBundle\Security\Core\Exception\AccountNotLinkedException;
use HWI\Bundle\OAuthBundle\Security\Core\User\OAuthAwareUserProviderInterface;
use App\Entity\User;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use App\Service\Scheduler;

class UserProvider implements OAuthAwareUserProviderInterface, UserProviderInterface
{
    /**
     * @var UserManagerInterface
     */
    private $userManager;

    /**
     * @var UserProviderInterface
     */
    private $userProvider;

    /**
     * @var Scheduler
     */
    private $scheduler;

    /**
     * @param UserManagerInterface  $userManager
     * @param UserProviderInterface $userProvider
     */
    public function __construct(UserManagerInterface $userManager, UserProviderInterface $userProvider, Scheduler $scheduler)
    {
        $this->userManager = $userManager;
        $this->userProvider = $userProvider;
        $this->scheduler = $scheduler;
    }

    /**
     * {@inheritDoc}
     */
    public function connect($user, UserResponseInterface $response)
    {
        $username = $response->getUsername();
        if (!$username || $username <= 0) {
            throw new \LogicException('Failed to load info from GitHub');
        }

        /** @var User $previousUser */
        $previousUser = $this->userManager->findUserBy(array('githubId' => $username));

        /** @var User $user */
        $user->setGithubId($username);
        $user->setGithubToken($response->getAccessToken());
        $user->setGithubScope($response->getOAuthToken()->getRawToken()['scope']);

        // The account is already connected. Do nothing
        if ($previousUser === $user) {
            return;
        }

        // 'disconnect' a previous account
        if (null !== $previousUser) {
            $previousUser->setGithubId(null);
            $previousUser->setGithubToken(null);
            $this->userManager->updateUser($previousUser);
        }

        $this->userManager->updateUser($user);

        $this->scheduler->scheduleUserScopeMigration($user->getId(), '', $user->getGithubScope());
    }

    /**
     * {@inheritDoc}
     */
    public function loadUserByOAuthUserResponse(UserResponseInterface $response)
    {
        $username = $response->getUsername();
        if (!$username || $username <= 0) {
            throw new \LogicException('Failed to load info from GitHub');
        }

        /** @var User $user */
        $user = $this->userManager->findUserBy(array('githubId' => $username));

        if (!$user) {
            throw new AccountNotLinkedException(sprintf('No user with github username "%s" was found.', $username));
        }

        if ($user->getGithubId() !== (string) $response->getUsername()) {
            throw new \LogicException('This really should not happen but checking just in case');
        }

        if ($user->getGithubToken() !== $response->getAccessToken()) {
            $user->setGithubToken($response->getAccessToken());
            $oldScope = $user->getGithubScope();
            $user->setGithubScope($response->getOAuthToken()->getRawToken()['scope']);
            $this->userManager->updateUser($user);
            if ($oldScope !== $user->getGithubScope()) {
                $this->scheduler->scheduleUserScopeMigration($user->getId(), $oldScope ?: '', $user->getGithubScope());
            }
        }

        return $user;
    }

    /**
     * {@inheritDoc}
     */
    public function loadUserByUsername($usernameOrEmail)
    {
        return $this->userProvider->loadUserByUsername($usernameOrEmail);
    }

    /**
     * {@inheritDoc}
     */
    public function refreshUser(UserInterface $user)
    {
        return $this->userProvider->refreshUser($user);
    }

    /**
     * {@inheritDoc}
     */
    public function supportsClass($class)
    {
        return $this->userProvider->supportsClass($class);
    }
}
