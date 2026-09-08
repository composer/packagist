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

namespace App\Organization\Domain\Exception;

/**
 * An org policy required for acceptance is not satisfied (currently: 2FA is mandatory to become an
 * owner). Acceptance is blocked and the invitation stays pending until the invitee complies.
 */
final class PolicyNotMetException extends OrganizationException
{
}
