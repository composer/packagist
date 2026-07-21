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
 * backing string gets persisted in `organization_event.type`.
 */
enum OrganizationEventType: string
{
    case OrganizationCreated = 'organization-created';
    case OrganizationNameChanged = 'organization-name-changed';
    case OrganizationSlugChanged = 'organization-slug-changed';
    case TeamCreated = 'team-created';
    case TeamRenamed = 'team-renamed';
    case TeamMemberAdded = 'team-member-added';
    case TeamMemberRemoved = 'team-member-removed';
    case TeamDeleted = 'team-deleted';
    case MemberRemoved = 'member-removed';
    case MemberLeft = 'member-left';

    // A member joining through an accepted invitation. Lives on the org stream (the org aggregate is
    // the source of truth for membership) but is the org-side half of the acceptance, appended in the
    // same transaction as the invitation stream's UserInvitationAccepted.
    case MemberJoined = 'member-joined';

    // Invitation aggregate (a separate ULID stream in the same event table). These are internal only:
    // they never reach the public transparency log; the acceptance is surfaced publicly by the org
    // stream's MemberJoined instead.
    case UserInvitationSent = 'user-invitation-sent';
    case UserInvitationResent = 'user-invitation-resent';
    case UserInvitationRevoked = 'user-invitation-revoked';
    case UserInvitationDeclined = 'user-invitation-declined';
    case UserInvitationAccepted = 'user-invitation-accepted';
    case UserInvitationExpired = 'user-invitation-expired';
}
