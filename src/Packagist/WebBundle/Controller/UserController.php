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

use Doctrine\ORM\NoResultException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use FOS\UserBundle\Model\UserInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Packagist\WebBundle\Entity\User;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Model\RedisAdapter;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UserController extends Controller
{
    /**
     * @Template()
     * @Route("/users/{name}/packages/", name="user_packages")
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     */
    public function packagesAction(Request $req, User $user)
    {
        $packages = $this->getUserPackages($req, $user);

        return array(
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($packages),
            'user' => $user,
        );
    }

    public function myProfileAction(Request $req)
    {
        $user = $this->container->get('security.context')->getToken()->getUser();
        if (!is_object($user) || !$user instanceof UserInterface) {
            throw new AccessDeniedException('This user does not have access to this section.');
        }

        $packages = $this->getUserPackages($req, $user);

        return $this->container->get('templating')->renderResponse(
            'FOSUserBundle:Profile:show.html.'.$this->container->getParameter('fos_user.template.engine'),
            array(
                'packages' => $packages,
                'meta' => $this->getPackagesMetadata($packages),
                'user' => $user,
            )
        );
    }


    /**
     * @Template()
     * @Route("/users/{name}/", name="user_profile")
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     */
    public function profileAction(Request $req, User $user)
    {
        $packages = $this->getUserPackages($req, $user);

        return array(
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($packages),
            'user' => $user,
        );
    }

    /**
     * @Template()
     * @Route("/users/{name}/favorites/", name="user_favorites")
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     * @Method({"GET"})
     */
    public function favoritesAction(Request $req, User $user)
    {
        try {
            if (!$this->get('snc_redis.default')->isConnected()) {
                $this->get('snc_redis.default')->connect();
            }
        } catch (\Exception $e) {
            $this->get('session')->getFlashBag()->set('error', 'Could not connect to the Redis database.');
            $this->get('logger')->notice($e->getMessage(), array('exception' => $e));

            return array('user' => $user, 'packages' => array());
        }

        $paginator = new Pagerfanta(
            new RedisAdapter($this->get('packagist.favorite_manager'), $user, 'getFavorites', 'getFavoriteCount')
        );

        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage($req->query->get('page', 1), false, true);

        return array('packages' => $paginator, 'user' => $user);
    }

    /**
     * @Route("/users/{name}/favorites/", name="user_add_fav", defaults={"_format" = "json"})
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     * @Method({"POST"})
     */
    public function postFavoriteAction(User $user)
    {
        if ($user->getId() !== $this->getUser()->getId()) {
            throw new AccessDeniedException('You can only change your own favorites');
        }

        $req = $this->getRequest();

        $package = $req->request->get('package');
        try {
            $package = $this->getDoctrine()
                ->getRepository('PackagistWebBundle:Package')
                ->findOneByName($package);
        } catch (NoResultException $e) {
            throw new NotFoundHttpException('The given package "'.$package.'" was not found.');
        }

        $this->get('packagist.favorite_manager')->markFavorite($user, $package);

        return new Response('{"status": "success"}', 201);
    }

    /**
     * @Route("/users/{name}/favorites/{package}", name="user_remove_fav", defaults={"_format" = "json"}, requirements={"package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"})
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     * @ParamConverter("package", options={"mapping": {"package": "name"}})
     * @Method({"DELETE"})
     */
    public function deleteFavoriteAction(User $user, Package $package)
    {
        if ($user->getId() !== $this->getUser()->getId()) {
            throw new AccessDeniedException('You can only change your own favorites');
        }

        $this->get('packagist.favorite_manager')->removeFavorite($user, $package);

        return new Response('{"status": "success"}', 204);
    }

    protected function getUserPackages($req, $user)
    {
        $packages = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:Package')
            ->getFilteredQueryBuilder(array('maintainer' => $user->getId()))
            ->orderBy('p.name');

        $paginator = new Pagerfanta(new DoctrineORMAdapter($packages, true));
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage($req->query->get('page', 1), false, true);

        return $paginator;
    }
}
