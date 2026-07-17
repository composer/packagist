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

use Symfony\Component\Validator\Constraints as Assert;

class InviteMemberRequest
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 255)]
    public string $email = '';

    /**
     * The selected target team ids (ULID strings). At least one team must be chosen.
     *
     * @var list<string>
     */
    #[Assert\Count(min: 1, minMessage: 'Select at least one team.')]
    public array $teamIds = [];
}
