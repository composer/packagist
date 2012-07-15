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

namespace Packagist\WebBundle\Form\Handler;

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
     */
    public function __construct(UserManagerInterface $userManager, TokenGeneratorInterface $tokenGenerator)
    {
        $this->tokenGenerator = $tokenGenerator;
        $this->userManager = $userManager;
    }

    /**
     * {@inheritDoc}
     */
    function process(Request $request, Form $form, UserResponseInterface $userInformation)
    {
        $user = $this->userManager->createUser();

        $form->setData($user);

        if ('POST' === $request->getMethod()) {
            $form->bindRequest($request);

            if ($form->isValid()) {
                $randomPassword = $this->tokenGenerator->generateToken();
                $form->getData()->setPassword($randomPassword);

                return true;
            }
        // if the form is not posted we'll try to set some properties
        } else {
            $user = $form->getData();

            $user->setUsername($this->getUniqueUsername($userInformation->getUsername()));

            if ($userInformation instanceof AdvancedUserResponseInterface) {
                $user->setEmail($userInformation->getEmail());
            }

            $form->setData($user);
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
