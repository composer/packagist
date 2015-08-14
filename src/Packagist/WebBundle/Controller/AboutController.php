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
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class AboutController extends Controller
{
    /**
     * @Template()
     * @Route("/about", name="about")
     */
    public function aboutAction()
    {
        return array();
    }

    /**
     * @Route("/about-composer")
     */
    public function aboutComposerFallbackAction()
    {
        return new RedirectResponse('https://getcomposer.org/', 301);
    }
}
