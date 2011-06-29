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

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Form\PackageType;
use Packagist\WebBundle\Form\VersionType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class WebController extends Controller
{
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

        $request = $this->get('request');
        if ($request->getMethod() == 'POST') {
            $form->bindRequest($request);
            if ($form->isValid()) {
                try {
                    $user = $this->get('security.context')->getToken()->getUser();
                    $package->addMaintainers($user);
                    $em = $this->get('doctrine')->getEntityManager();
                    $em->persist($package);
                    $em->flush();

                    $this->get('session')->setFlash('success', $package->getName().' has been added to the package list, now go ahead and add a release!');
                    return new RedirectResponse($this->generateUrl('submit_version', array('package' => $package->getName())));
                } catch (\PDOExceptionx $e) {
                    $this->get('session')->setFlash('error', $package->getName().' could not be saved in our database, most likely the name is already in use.');
                }
            }
        }

        return array('form' => $form->createView(), 'page' => 'submit');
    }

    /**
     * @Template()
     * @Route("/submit/{package}", name="submit_version")
     */
    public function submitVersionAction($package)
    {
        $em = $this->get('doctrine')->getEntityManager();

        $pkg = $this->get('doctrine')->getRepository('Packagist\WebBundle\Entity\Package')
            ->findOneByName($package);

        if (!$pkg) {
            throw new NotFoundHttpException('Package '.$package.' not found.');
        }

        // TODO populate with the latest version's data
        $version = new Version;
        $version->setEntityManager($em);
        $version->setName($pkg->getName());
        $version->setDescription($pkg->getDescription());
        $form = $this->get('form.factory')->create(new VersionType, $version);

        $request = $this->get('request');
        if ($request->getMethod() == 'POST') {
            $form->bindRequest($request);

            if ($form->isValid()) {
                try {
                    // TODO check if this is the latest version to move the latest dist-tags reference, and update the package's description perhaps
                    $pkg->addVersions($version);
                    $version->setPackage($pkg);
                    $em->persist($version);
                    $em->flush();

                    $this->get('session')->setFlash('success', $pkg->getName().'\'s version '.$version->getVersion().' has been added.');
                    return new RedirectResponse($this->generateUrl('home'));
                } catch (\PDOException $e) {
                    $this->get('session')->setFlash('error', $pkg->getName().'\'s version '.$version->getVersion().' could not be saved in our database, most likely it already exists.');
                }
            }
        }

        return array('form' => $form->createView(), 'package' => $pkg, 'page' => 'submit');
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
