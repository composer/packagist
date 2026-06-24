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

use App\Organization\Domain\Organization;
use App\Organization\Domain\Slug;
use App\Validator\NotReservedWord;
use Symfony\Component\Validator\Constraints as Assert;

class CreateOrganizationRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(max: Slug::MAX_LENGTH)]
    #[Assert\Regex(
        pattern: '/^' . Slug::PATTERN . '$/',
        message: 'The slug may only contain lowercase letters, numbers and hyphens, with no leading or trailing hyphen.',
    )]
    #[NotReservedWord]
    public string $slug = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: Organization::DISPLAY_NAME_MAX_LENGTH)]
    #[Assert\Regex(
        pattern: '/^[\p{L}\p{N}\- ]+$/u',
        message: 'The display name may only contain letters, numbers, spaces and hyphens.',
    )]
    public string $displayName = '';
}
