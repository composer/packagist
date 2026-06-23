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

namespace App\Organization\EventStore;

/**
 * Canonical event type identifiers for the organization event stream. The
 * backing string is what gets persisted in `organization_event.type`.
 */
enum OrganizationEventType: string
{
    case OrganizationCreated = 'organization-created';
}
