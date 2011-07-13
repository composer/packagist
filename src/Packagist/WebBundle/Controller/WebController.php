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

use Packagist\WebBundle\Form\ConfirmPackageType;
use Packagist\WebBundle\Form\ConfirmForm;
use Packagist\WebBundle\Form\ConfirmFormType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Form\PackageType;
use Packagist\WebBundle\Form\VersionType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

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
        $packages = $this->get('doctrine')
            ->getRepository('Packagist\WebBundle\Entity\Package')
            ->findAll();

        return array('packages' => $packages, 'page' => 'home');
    }

    /**
     * @Template()
     * @Route("/submit", name="submit")
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
                    $package->addMaintainers($user);
                    $em = $this->get('doctrine')->getEntityManager();
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
     * @Template()
     * @Route("/submit/fetch-info", name="submit.fetch_info", defaults={"_format"="json"})
     */
    public function fetchInfoAction()
    {
        // TODO refactor, this must validate then retrive the name and return that as json, or just return the errors
        $session = $this->get('session');
        $em = $this->getDoctrine()->getEntityManager();
        $package = new Package;

        if ($repository = $session->get('repository')) {
            $session->remove('repository');
            $package->setRepository($repository);
            $package->fromProvider($this->get('packagist.repository_provider'));
        }

        $form = $this->createForm(new ConfirmPackageType, $package);

        $request = $this->getRequest();
        if ($request->getMethod() == 'POST') {
            $form->bindRequest($request);
            $package->fromProvider($this->get('packagist.repository_provider'));

            $children = $form->getChildren();
            if ($children['repository']->isValid()) {
                $user = $this->getUser();
                $package->addMaintainers($user);

                $em = $this->getDoctrine()->getEntityManager();
                $em->persist($package);
                $em->flush();

                $this->get('session')->remove('repository');

                return new RedirectResponse($this->generateUrl('home'));
            }
        } elseif (!$repository) {
            return new RedirectResponse($this->generateUrl('submit'));
        }

        return array('form' => $form->createView(), 'package' => $package, 'page' => 'submit');
    }

    /**
     * @Template()
     * @Route("/about", name="about")
     */
    public function aboutAction()
    {
        return array();
    }
}
