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

use App\Organization\Domain\TeamName;
use Symfony\Component\Validator\Constraints as Assert;

class TeamRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(max: TeamName::MAX_LENGTH)]
    #[Assert\Regex(pattern: '/^' . TeamName::PATTERN . '$/u', message: 'The team name may only contain letters, numbers, spaces and hyphens.')]
    public string $name = '';
}
