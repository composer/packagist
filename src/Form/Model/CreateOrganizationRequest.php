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

use App\Validator\NotReservedWord;
use App\Validator\ValidDisplayName;
use App\Validator\ValidSlug;
use Symfony\Component\Validator\Constraints as Assert;

class CreateOrganizationRequest
{
    #[Assert\NotBlank]
    #[ValidSlug]
    #[NotReservedWord]
    public string $slug = '';

    #[Assert\NotBlank]
    #[ValidDisplayName]
    #[NotReservedWord]
    public string $displayName = '';
}
