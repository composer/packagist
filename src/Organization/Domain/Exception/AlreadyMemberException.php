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
 * The invitee is already a member of the organization, so there is nothing to accept. An owner adds an
 * existing member to further teams through team management, not by inviting them again.
 */
final class AlreadyMemberException extends OrganizationException
{
}
