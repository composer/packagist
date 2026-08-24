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

namespace App\Organization\Domain;

/**
 * Lifecycle of an {@see Invitation}. Only {@see self::Pending} is an active state; every other value is
 * terminal (a resolved invitation is never revived, a fresh {@see \App\Organization\Domain\Event\UserInvitationSent}
 * is required instead). The backing values are persisted in the {@see \App\Entity\OrganizationInvitation}
 * read model and event payloads, so they must remain stable.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Declined = 'declined';
    case Expired = 'expired';

    public function isPending(): bool
    {
        return $this === self::Pending;
    }
}
