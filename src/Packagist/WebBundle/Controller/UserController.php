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
     * @Route("/user/{id}/packages", name="user_packages")
     */
    public function packagesAction($id)
    {
        $user = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:User')
            ->findOneById($id);

        if (empty($user)) {
            throw new NotFoundHttpException();
        }

        return array('user' => $user);
    }
}