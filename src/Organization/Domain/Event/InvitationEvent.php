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
 * Marker for events on the Invitation aggregate stream. Lets the org projectors route invitation events
 * to their own handling ({@see \App\Organization\Projection\InvitationReadModelProjector} maintains the
 * read model, {@see \App\Organization\Projection\OrganizationAuditProjector} publishes the transparency
 * log entry) while still catching genuinely-unhandled org events.
 *
 * The aggregate id of an invitation event is the invitation's ULID, not the org's.
 */
interface InvitationEvent extends DomainEvent
{
    /**
     * The invited email address. Written to the transparency log, where the display layer obfuscates it
     * from anyone who is neither an auditor nor a member of the invitation's organization.
     */
    public function email(): string;
}
