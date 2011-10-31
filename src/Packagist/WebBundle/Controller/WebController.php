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

use Packagist\WebBundle\Form\Type\AddMaintainerRequestType;
use Packagist\WebBundle\Form\Model\AddMaintainerRequest;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Form\Type\PackageType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineORMAdapter;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class WebController extends Controller
{
    protected function getUser()
    {
        return $user = $this->get('security.context')->getToken()->getUser();
    }

    /**
     * @Template()
     * @Route("/", name="home")
     */
    public function indexAction()
    {
        return array('page' => 'home');
    }

    /**
     * @Template()
     * @Route("/packages/", name="browse")
     */
    public function browseAction(Request $req)
    {
        if ($tag = $req->query->get('tag')) {
            $packages = $this->getDoctrine()
                ->getRepository('PackagistWebBundle:Package')
                ->findByTag($tag);

            $paginator = new Pagerfanta(new DoctrineORMAdapter($packages, true));
            $paginator->setMaxPerPage(15);
            $paginator->setCurrentPage($req->query->get('page', 1), false, true);

            return $this->render('PackagistWebBundle:Web:tag.html.twig', array('packages' => $paginator, 'tag' => $tag));
        }

        $packages = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:Package')
            ->getBaseQueryBuilder();

        $paginator = new Pagerfanta(new DoctrineORMAdapter($packages, true));
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage($req->query->get('page', 1), false, true);

        return $this->render('PackagistWebBundle:Web:browse.html.twig', array('packages' => $paginator));
    }

    /**
     * @Template()
     * @Route("/packages/submit", name="submit")
     */
    public function submitPackageAction()
    {
        $package = new Package;
        $package->setRepositoryProvider($this->get('packagist.repository_provider'));
        $form = $this->createForm(new PackageType, $package);

        $request = $this->getRequest();
        if ($request->getMethod() == 'POST') {
            $form->bindRequest($request);
            if ($form->isValid()) {
                try {
                    $user = $this->getUser();
                    $package->addMaintainer($user);
                    $em = $this->getDoctrine()->getEntityManager();
                    $em->persist($package);
                    $em->flush();

                    $this->get('session')->setFlash('success', $package->getName().' has been added to the package list, the repository will be parsed for releases in a bit.');

                    return new RedirectResponse($this->generateUrl('home'));
                } catch (\Exception $e) {
                    $this->get('logger')->crit($e->getMessage(), array('exception', $e));
                    $this->get('session')->setFlash('error', $package->getName().' could not be saved.');
                }
            }
        }

        return array('form' => $form->createView(), 'page' => 'submit');
    }

    /**
     * @Route("/packages/fetch-info", name="submit.fetch_info", defaults={"_format"="json"})
     */
    public function fetchInfoAction()
    {
        $package = new Package;
        $package->setRepositoryProvider($this->get('packagist.repository_provider'));
        $form = $this->createForm(new PackageType, $package);

        $response = array('status' => 'error', 'reason' => 'No data posted.');
        $request = $this->getRequest();
        if ('POST' === $request->getMethod()) {
            $form->bindRequest($request);
            if ($form->isValid()) {
                $response = array('status' => 'success', 'name' => $package->getName());
            } else {
                $errors = array();
                foreach ($form->getChildren() as $child) {
                    if ($child->hasErrors()) {
                        foreach ($child->getErrors() as $error) {
                            $errors[] = $error->getMessageTemplate();
                        }
                    }
                }
                $response = array('status' => 'error', 'reason' => $errors);
            }
        }

        return new Response(json_encode($response));
    }

    /**
     * @Template()
     * @Route("/packages/{name}", name="view", requirements={"name"="[A-Za-z0-9/_-]+"})
     */
    public function viewAction($name)
    {
        $package = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:Package')
            ->findOneByName($name);

        if (!$package) {
            throw new NotFoundHttpException('The requested package, '.$name.', was not found.');
        }

        if ($package->getMaintainers()->contains($this->getUser())) {

            $addMaintainerRequest = new AddMaintainerRequest;
            $form = $this->createForm(new AddMaintainerRequestType, $addMaintainerRequest);

            $request = $this->getRequest();
            if ('POST' === $request->getMethod()) {
                $form->bindRequest($request);
                if ($form->isValid()) {
                    try {
                        $em = $this->getDoctrine()->getEntityManager();
                        $user = $addMaintainerRequest->getUser();

                        if (empty($user)) {
                            $this->get('session')->setFlash('error', 'The user could not be found.');

                            return array('package' => $package, 'form' => $form->createView());
                        }

                        $package->addMaintainers($user);

                        $em->persist($package);
                        $em->flush();

                        $this->get('session')->setFlash('success', $user->getUsername().' is now a '.$package->getName().' maintainer.');

                        return new RedirectResponse($this->generateUrl('home'));
                    } catch (\Exception $e) {
                        $this->get('logger')->crit($e->getMessage(), array('exception', $e));
                        $this->get('session')->setFlash('error', 'The maintainer could not be added.');
                    }
                }
            }

            return array('package' => $package, 'form' => $form->createView());
        }

        return array('package' => $package);
    }
}
