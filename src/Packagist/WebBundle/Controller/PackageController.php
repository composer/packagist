<?php

namespace Packagist\WebBundle\Controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

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
    function editAction(Request $req, $name)
    {
        $package = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:Package')
            ->findOneByName($name);

        if (!$package) {
            throw new NotFoundHttpException("The requested package, $name, could not be found.");
        }

        $form = $this->createFormBuilder($package)
            ->add("repository", "text")
            ->getForm();

        if ($req->getMethod() == 'POST') {
            $form->bindRequest($req);

            if ($form->isValid()) {
                // Save
            }
        }

        return array("package" => $package, "form" => $form->createView());
    }

    /**
     * @Route("/packages/{name}/update", name="package_update")
     */
    function updateAction()
    {
    }
}

