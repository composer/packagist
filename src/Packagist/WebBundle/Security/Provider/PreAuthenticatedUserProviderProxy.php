<?php
/**
 * @author strati <strati@strati.hu>
 */

namespace Packagist\WebBundle\Security\Provider;

use FOS\UserBundle\Model\UserManagerInterface;
use FOS\UserBundle\Util\TokenGeneratorInterface;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use HWI\Bundle\OAuthBundle\Security\Core\User\OAuthAwareUserProviderInterface;
use Packagist\WebBundle\Entity\User as PackagistUser;
use Packagist\WebBundle\Security\Provider\UserProvider as PackagistUserProvider;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * User provider proxy to be used with basic http authentication method (eg. mod_auth_ldap)
 *
 * If the proxied UserProvider can't find a user based on given user name, a new user with some default
 * parameters will be created
 */
class PreAuthenticatedUserProviderProxy implements OAuthAwareUserProviderInterface, UserProviderInterface
{
    /**
     * @var PackagistUserProvider
     */
    private $packagistUserProvider;

    /**
     * @var TokenGeneratorInterface
     */
    private $tokenGenerator;

    /**
     * @var UserManagerInterface
     */
    private $userManager;

    /**
     * @var string
     */
    private $defaultEmailDomain;

    /**
     * @param UserProvider            $packagistUserProvider
     * @param TokenGeneratorInterface $tokenGenerator
     * @param UserManagerInterface    $userManager
     * @param string                  $defaultEmailDomain
     */
    public function __construct(
        PackagistUserProvider $packagistUserProvider,
        TokenGeneratorInterface $tokenGenerator,
        UserManagerInterface $userManager,
        $defaultEmailDomain
    ) {
        $this->packagistUserProvider = $packagistUserProvider;
        $this->tokenGenerator        = $tokenGenerator;
        $this->userManager           = $userManager;
        $this->defaultEmailDomain    = $defaultEmailDomain;
    }

    /**
     * Loads the user by a given UserResponseInterface object.
     *
     * @param UserResponseInterface $response
     *
     * @return \Symfony\Component\Security\Core\User\UserInterface
     *
     * @throws UsernameNotFoundException if the user is not found
     */
    public function loadUserByOAuthUserResponse(UserResponseInterface $response)
    {
        return $this->packagistUserProvider->loadUserByOAuthUserResponse($response);
    }

    /**
     * Loads the user for the given username.
     *
     * This method must throw UsernameNotFoundException if the user is not
     * found.
     *
     * @param string $username The username
     *
     * @return \Symfony\Component\Security\Core\User\UserInterface
     *
     * @see UsernameNotFoundException
     *
     * @throws UsernameNotFoundException if the user is not found
     *
     */
    public function loadUserByUsername($username)
    {
        try {
            $user = $this->packagistUserProvider->loadUserByUsername($username);
        } catch (UsernameNotFoundException $e) {
            $user = $this->autoCreateUserWithDefaultData($username);
        }

        return $user;
    }

    /**
     * Create a user when no user found with preauthenticated data
     *
     * @param string $userName
     *
     * @return \Symfony\Component\Security\Core\User\UserInterface
     */
    private function autoCreateUserWithDefaultData($userName)
    {
        $user = $this->userManager->createUser();
        /* @var PackagistUser $user */
        $user->setUsername($userName);
        $user->setPlainPassword($user->getUsername());
        $user->setEmail("{$userName}@{$this->defaultEmailDomain}");

        $apiToken = substr($this->tokenGenerator->generateToken(), 0, 20);

        $user->setApiToken($apiToken);
        $user->setEnabled(true);

        $this->userManager->updateUser($user);

        return $user;
    }

    /**
     * Refreshes the user for the account interface.
     *
     * It is up to the implementation to decide if the user data should be
     * totally reloaded (e.g. from the database), or if the UserInterface
     * object can just be merged into some internal array of users / identity
     * map.
     *
     * @param \Symfony\Component\Security\Core\User\UserInterface $user
     *
     * @return \Symfony\Component\Security\Core\User\UserInterface
     *
     * @throws UnsupportedUserException if the account is not supported
     */
    public function refreshUser(\Symfony\Component\Security\Core\User\UserInterface $user)
    {
        return $this->packagistUserProvider->refreshUser($user);
    }

    /**
     * Whether this provider supports the given user class
     *
     * @param string $class
     *
     * @return Boolean
     */
    public function supportsClass($class)
    {
        return $this->packagistUserProvider->supportsClass($class);
    }
}