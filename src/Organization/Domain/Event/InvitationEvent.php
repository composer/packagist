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

namespace App\Organization\Domain\Event;

use App\Organization\EventStore\DomainEvent;

/**
 * Marker for events on the Invitation aggregate stream. Lets the org projectors ignore invitation
 * events (they have their own {@see \App\Organization\Projection\InvitationReadModelProjector} and are
 * never published to the public transparency log) while still catching genuinely-unhandled org events.
 *
 * The aggregate id of an invitation event is the invitation's ULID, not the org's.
 */
interface InvitationEvent extends DomainEvent
{
    /**
     * The invited email address. Stored in the read model and event payload for the owner's management
     * view, but never written to the public transparency log.
     */
    public function email(): string;
}
