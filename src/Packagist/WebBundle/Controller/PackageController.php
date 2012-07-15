<?php

namespace Packagist\WebBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Package\Updater;

use Composer\IO\NullIO;
use Composer\Factory;
use Composer\Repository\VcsRepository;

class PackageController extends Controller
{
    /**
     * @Template()
     * @Route(
     *     "/packages/{name}/edit",
     *     name="edit_package",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     */
    public function editAction(Request $req, $name)
    {
        /** @var $packageRepo \Packagist\WebBundle\Entity\PackageRepository */
        $packageRepo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');
        /** @var $package Package */
        $package = $packageRepo->findOneByName($name);

        if (!$package) {
            throw $this->createNotFoundException("The requested package, $name, could not be found.");
        }

        if (!$package->getMaintainers()->contains($this->getUser()) && !$this->get('security.context')->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException;
        }

        $form = $this->createFormBuilder($package, array("validation_groups" => array("update")))
            ->add("repository", "text")
            ->getForm();

        if ($req->isMethod("POST")) {
            $package->setEntityRepository($packageRepo);

            $form->bind($req);

            if ($form->isValid()) {
                // Force updating of packages once the package is viewed after the redirect.
                $package->setCrawledAt(null);

                $em = $this->getDoctrine()->getManager();
                $em->persist($package);
                $em->flush();

                $this->get("session")->getFlashBag()->set("success", "Changes saved.");

                return $this->redirect(
                    $this->generateUrl("view_package", array("name" => $package->getName()))
                );
            }
        }

        return array(
            "package" => $package, "form" => $form->createView()
        );
    }
}

