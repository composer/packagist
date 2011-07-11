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
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ApiController extends Controller
{
    /**
     * @Template()
     * @Route("/packages.json", name="packages")
     */
    public function packagesAction()
    {
        $version = $this->get('request')->query->get('version');

        $packages = $this->get('doctrine')
            ->getRepository('Packagist\WebBundle\Entity\Package')
            ->findAll();

        $data = '{';
        $cnt = count($packages);
        foreach ($packages as $idx => $package) {
            $data .= '"'.$package->getName().'":'.$package->toJson();
            if ($cnt > $idx+1) {
                $data .= ',';
            }
        }
        $data .= '}';

        return new Response($data, 200, array('Content-Type' => 'application/json'));
    }
}
