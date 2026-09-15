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

/**
 * The account is identified by the two-factor token in the session, never by anything submitted
 * here, so this carries nothing but an optional note.
 */
class LostTwoFactorSupportRequest
{
    #[Assert\Length(max: 2000)]
    public ?string $description = null;
}
