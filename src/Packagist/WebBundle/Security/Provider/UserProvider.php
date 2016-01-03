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
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

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
     * @param UserManagerInterface  $userManager
     * @param UserProviderInterface $userProvider
     */
    public function __construct(UserManagerInterface $userManager, UserProviderInterface $userProvider)
    {
        $this->userManager = $userManager;
        $this->userProvider = $userProvider;
    }

    /**
     * {@inheritDoc}
     */
    public function connect($user, UserResponseInterface $response)
    {
        $username = $response->getUsername();

        /** @var User $previousUser */
        $previousUser = $this->userManager->findUserBy(array('githubId' => $username));

        /** @var User $user */
        $user->setGithubId($username);
        $user->setGithubToken($response->getAccessToken());

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
    }

    /**
     * {@inheritDoc}
     */
    public function loadUserByOAuthUserResponse(UserResponseInterface $response)
    {
        $username = $response->getUsername();
        /** @var User $user */
        $user = $this->userManager->findUserBy(array('githubId' => $username));

        if (!$user) {
            throw new AccountNotLinkedException(sprintf('No user with github username "%s" was found.', $username));
        }

        if ($user->getGithubToken() !== $response->getAccessToken()) {
            $user->setGithubToken($response->getAccessToken());
            $this->userManager->updateUser($user);
        }

        return $user;
    }

    /**
     * {@inheritDoc}
     */
    public function loadUserByUsername($usernameOrEmail)
    {
        $user = $this->userProvider->loadUserByUsername($usernameOrEmail);

        return $user;
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
