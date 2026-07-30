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

namespace App\Organization\Http;

/**
 * Signals that an invitation link carried a valid token but its invitation has run out, so the visitor
 * demonstrably received the email and deserves an explanation instead of a 404. Turned into a 410 with
 * the invitee-facing expiry page by {@see \App\EventListener\InvitationExpiredListener}.
 */
final class InvitationExpiredException extends \RuntimeException
{
    public function __construct(public readonly \DateTimeImmutable $expiresAt)
    {
        parent::__construct(sprintf('Invitation link expired at %s.', $expiresAt->format('c')));
    }
}
