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
 * No live pending invitation to act on. Declined, revoked and expired invitations are terminal, so a
 * fresh invitation is required rather than reviving one.
 */
final class NoPendingInvitationException extends OrganizationException
{
}
