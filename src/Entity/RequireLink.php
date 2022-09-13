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
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
#[ORM\Entity]
#[ORM\Table(name: 'link_require')]
#[ORM\Index(name: 'link_require_package_name_idx', columns: ['version_id', 'packageName'])]
#[ORM\Index(name: 'link_require_name_idx', columns: ['packageName'])]
class RequireLink extends PackageLink
{
    #[ORM\ManyToOne(targetEntity: 'App\Entity\Version', inversedBy: 'require')]
    protected Version|null $version = null;
}
