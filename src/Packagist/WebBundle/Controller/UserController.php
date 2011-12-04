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
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineORMAdapter;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UserController extends Controller
{
    /**
     * @Template()
     * @Route("/users/{name}/packages/", name="user_packages")
     */
    public function packagesAction(Request $req, $name)
    {
        $user = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:User')
            ->findOneByUsername($name);

        if (!$user) {
            throw new NotFoundHttpException('The requested user, '.$name.', could not be found.');
        }

        return array('packages' => $this->getUserPackages($req, $user), 'user' => $user);
    }

    /**
     * @Template()
     * @Route("/users/{name}/", name="user_profile")
     */
    public function profileAction(Request $req, $name)
    {
        $user = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:User')
            ->findOneByUsername($name);

        if (!$user) {
            throw new NotFoundHttpException('The requested user, '.$name.', could not be found.');
        }

        return array('packages' => $this->getUserPackages($req, $user), 'user' => $user);
    }

    protected function getUserPackages($req, $user)
    {
        $packages = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:Package')
            ->getQueryBuilderByMaintainer($user);

        $paginator = new Pagerfanta(new DoctrineORMAdapter($packages, true));
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage($req->query->get('page', 1), false, true);

        return $paginator;
    }
}