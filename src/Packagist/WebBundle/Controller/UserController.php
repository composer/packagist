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

namespace Packagist\WebBundle\Controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

class UserController extends Controller
{
    /**
     * @Template()
     * @Route("/user/{name}", name="user_profile")
     */
    public function profileAction($name)
    {
        $user = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:User')
            ->findOneByUsername($name);

        if (!$user) {
            throw new NotFoundHttpException('The requested user, '.$name.', could not be found.');
        }

        return array('user' => $user, 'packages' => $user->getPackages());
    }
}