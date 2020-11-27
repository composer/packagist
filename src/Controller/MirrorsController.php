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

namespace App\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class MirrorsController extends Controller
{
    /**
     * @Template()
     * @Route("/mirrors", name="mirrors")
     */
    public function indexAction()
    {
        return array();
    }
}
