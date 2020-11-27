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

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="link_provide")
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ProvideLink extends PackageLink
{
    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Version", inversedBy="provide")
     */
    protected $version;
}