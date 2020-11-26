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

namespace Packagist\WebBundle\Security\Provider;

use FOS\UserBundle\Model\UserManagerInterface;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use HWI\Bundle\OAuthBundle\Security\Core\Exception\AccountNotLinkedException;
use HWI\Bundle\OAuthBundle\Security\Core\User\OAuthAwareUserProviderInterface;
use Packagist\WebBundle\Entity\User;
use Packagist\WebBundle\Security\AccountEmailExistsWithoutGitHubException;
use Packagist\WebBundle\Security\AccountUsernameExistsWithoutGitHubException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Packagist\WebBundle\Service\Scheduler;
use FOS\UserBundle\Util\TokenGenerator;

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
     * @var TokenGenerator
     */
    private $tokenGenerator;

    /**
     * @param UserManagerInterface  $userManager
     * @param UserProviderInterface $userProvider
     */
    public function __construct(UserManagerInterface $userManager, UserProviderInterface $userProvider, Scheduler $scheduler, TokenGenerator $tokenGenerator)
    {
        $this->userManager = $userManager;
        $this->userProvider = $userProvider;
        $this->scheduler = $scheduler;
        $this->tokenGenerator = $tokenGenerator;
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
            $tryByEmail = $this->userManager->findUserByEmail($response->getEmail());
            if ($tryByEmail) {
                throw new AccountEmailExistsWithoutGitHubException();
            }
            $tryByUsername = $this->userManager->findUserByUsername($response->getNickname());
            if ($tryByUsername) {
                throw new AccountUsernameExistsWithoutGitHubException();
            }

            // if null just create new user and set it properties
            $user = new User();
            $user->setUsername($response->getNickname());
            $user->setPlainPassword(random_bytes(40));
            $user->setEmail($response->getEmail());
            $user->setGithubId($username);
            $user->setGithubToken($response->getAccessToken());
            $user->setGithubScope($response->getOAuthToken()->getRawToken()['scope']);
            $apiToken = substr($this->tokenGenerator->generateToken(), 0, 20);
            $user->setApiToken($apiToken);
            $user->setEnabled(true);

            $this->userManager->updateUser($user);

            return $user;
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
