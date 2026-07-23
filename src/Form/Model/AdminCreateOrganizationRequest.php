<?php declare(strict_types=1);

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form\Model;

use App\Validator\UserExists;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Admin-only organization creation: reuses the shared slug/display name rules from
 * {@see OrganizationDetailsRequest} and adds the first owner, who is picked by an admin.
 */
class AdminCreateOrganizationRequest extends OrganizationDetailsRequest
{
    #[Assert\NotBlank]
    #[UserExists]
    public string $owner = '';
}
