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
        $form = $this->get('form.factory')->create(new PackageType, $package);

        $request = $this->getRequest();
        if ($request->getMethod() == 'POST') {
            $form->bindRequest($request);

            $provider = $this->get('packagist.repository_provider');
            $repository = $provider->getRepository($package->getRepository());
            $composerFile = $repository->getComposerInformation('master');

            $package->setName($composerFile['name']);
            if ($form->isValid()) {
                $user = $this->getUser();
                $package->addMaintainers($user);

                $em = $this->getDoctrine()->getEntityManager();
                $em->persist($package);
                $em->flush();

                return new RedirectResponse($this->generateUrl('home'));
            }
        }

        return array('form' => $form->createView(), 'page' => 'submit');
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
