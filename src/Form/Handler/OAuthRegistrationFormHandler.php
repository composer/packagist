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

namespace App\Form\Handler;

use FOS\UserBundle\Model\UserManagerInterface;
use FOS\UserBundle\Util\TokenGeneratorInterface;
use HWI\Bundle\OAuthBundle\Form\RegistrationFormHandlerInterface;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\Request;

/**
 * OAuthRegistrationFormHandler
 *
 * @author Alexander <iam.asm89@gmail.com>
 */
class OAuthRegistrationFormHandler implements RegistrationFormHandlerInterface
{
    private $userManager;
    private $tokenGenerator;

    /**
     * Constructor.
     *
     * @param UserManagerInterface $userManager
     * @param TokenGeneratorInterface $tokenGenerator
     */
    public function __construct(UserManagerInterface $userManager, TokenGeneratorInterface $tokenGenerator)
    {
        $this->tokenGenerator = $tokenGenerator;
        $this->userManager = $userManager;
    }

    /**
     * {@inheritDoc}
     */
    public function process(Request $request, Form $form, UserResponseInterface $userInformation)
    {
        $user = $this->userManager->createUser();

        // Try to get some properties for the initial form when coming from github
        if ('GET' === $request->getMethod()) {
            $user->setUsername($this->getUniqueUsername($userInformation->getNickname()));
            $user->setEmail($userInformation->getEmail());
        }

        $form->setData($user);

        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                if (!$user->getPassword() && !$user->getPlainPassword()) {
                    $randomPassword = $this->tokenGenerator->generateToken();
                    $user->setPlainPassword($randomPassword);
                }
                $user->setEnabled(true);

                $apiToken = substr($this->tokenGenerator->generateToken(), 0, 20);
                $user->setApiToken($apiToken);

                return true;
            }
        }

        return false;
    }

    /**
     * Attempts to get a unique username for the user.
     *
     * @param string $name
     *
     * @return string Name, or empty string if it failed after 10 times
     *
     * @see HWI\Bundle\OAuthBundle\Form\FOSUBRegistrationHandler
     */
    protected function getUniqueUserName($name)
    {
        $i = 0;
        $testName = $name;

        do {
            $user = $this->userManager->findUserByUsername($testName);
        } while ($user !== null && $i < 10 && $testName = $name.++$i);

        return $user !== null ? '' : $testName;
    }
}
