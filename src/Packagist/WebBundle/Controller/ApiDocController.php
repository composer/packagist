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

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be> 
 */
class ApiDocController extends Controller
{
    /**
     * @Template()
     * @Route("/apidoc", name="api_doc")
     */
    public function indexAction()
    {
        return array();
    }
}
