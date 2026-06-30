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

use App\Organization\Domain\DisplayName;
use App\Organization\Domain\Slug;
use App\Validator\NotReservedWord;
use App\Validator\ValidValueObject;
use Symfony\Component\Validator\Constraints as Assert;

class SaveOrganizationDetailsRequest
{
    #[Assert\NotBlank]
    #[ValidValueObject(Slug::class)]
    #[NotReservedWord]
    public string $slug = '';

    #[Assert\NotBlank]
    #[ValidValueObject(DisplayName::class)]
    #[NotReservedWord]
    public string $displayName = '';
}
