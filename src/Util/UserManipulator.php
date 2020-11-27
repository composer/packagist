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

namespace App\Util;

use FOS\UserBundle\Model\UserManagerInterface;
use FOS\UserBundle\Util\TokenGeneratorInterface;
use FOS\UserBundle\Util\UserManipulator as BaseManipulator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class UserManipulator extends BaseManipulator
{
    private $userManager;
    private $tokenGenerator;

    /**
     * {@inheritdoc}
     */
    public function __construct(
        UserManagerInterface $userManager,
        TokenGeneratorInterface $tokenGenerator,
        EventDispatcherInterface $dispatcher,
        RequestStack $requestStack
    )
    {
        $this->userManager = $userManager;
        $this->tokenGenerator = $tokenGenerator;

        parent::__construct($userManager, $dispatcher, $requestStack);
    }

    /**
     * {@inheritdoc}
     */
    public function create($username, $password, $email, $active, $superadmin)
    {
        $user = parent::create($username, $password, $email, $active, $superadmin);

        $apiToken = substr($this->tokenGenerator->generateToken(), 0, 20);
        $user->setApiToken($apiToken);

        $this->userManager->updateUser($user);

        return $user;
    }
}
