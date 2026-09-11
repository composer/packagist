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

namespace App\Log;

use App\Log\LogEventType;

/**
 * Holds two kinds of projected event:
 *  - package-native events (ownership / package / version): projected 1:1 from an audit row that
 *    already carries the packageId.
 *  - account events ({@see self::fansOutToMaintainedPackages()}): user-security events that carry no package. The
 *    projector fans each of these out to every package the user maintains, producing one entry per
 *    package.
 */
enum TransparencyLogEventType: string implements LogEventType
{
    // package ownership
    case MaintainerAdded = 'maintainer_added';
    case MaintainerRemoved = 'maintainer_removed';
    case PackageTransferred = 'package_transferred';

    // package management
    case PackageCreated = 'package_created';
    case CanonicalUrlChanged = 'canonical_url_changed';
    case PackageAbandoned = 'package_abandoned';
    case PackageUnabandoned = 'package_unabandoned';
    case PackageFrozen = 'package_frozen';
    case PackageUnfrozen = 'package_unfrozen';
    case PackageDeleted = 'package_deleted';

    // version
    case VersionCreated = 'version_created';
    case VersionReferenceChangeBlocked = 'version_reference_change_blocked';
    case VersionDeleted = 'version_deleted';
    case VersionSoftDeleted = 'version_soft_deleted';
    case VersionRecovered = 'version_recovered';

    // account security (fanned out to every package the user maintains)
    case TwoFactorAuthenticationActivated = 'two_fa_activated';
    case TwoFactorAuthenticationDeactivated = 'two_fa_deactivated';
    case PasswordReset = 'password_reset';
    case PasswordChanged = 'password_changed';
    case EmailChanged = 'email_changed';
    case GitHubLinkedWithUser = 'github_linked_with_user';
    case GitHubDisconnectedFromUser = 'github_disconnected_from_user';

    /**
     * Maps an internal audit record type onto its public transparency-log type, or null when the
     * event is out of scope for the package transparency log.
     *
     * Deliberately exhaustive, with no default arm: this is the only gate into the log
     * ({@see \App\Entity\PackageTransparencyLogQueueRepository::enqueue()}), so a default would let a
     * new audit record type be left out of the public log by omission rather than by decision. Adding
     * a case to {@see AuditLogEventType} therefore fails here until someone maps it or lists it below
     * as out of scope.
     */
    public static function fromAuditLogEventType(AuditLogEventType $type): ?self
    {
        return match ($type) {
            AuditLogEventType::MaintainerAdded => self::MaintainerAdded,
            AuditLogEventType::MaintainerRemoved => self::MaintainerRemoved,
            AuditLogEventType::PackageTransferred => self::PackageTransferred,
            AuditLogEventType::PackageCreated => self::PackageCreated,
            AuditLogEventType::CanonicalUrlChanged => self::CanonicalUrlChanged,
            AuditLogEventType::PackageAbandoned => self::PackageAbandoned,
            AuditLogEventType::PackageUnabandoned => self::PackageUnabandoned,
            AuditLogEventType::PackageFrozen => self::PackageFrozen,
            AuditLogEventType::PackageUnfrozen => self::PackageUnfrozen,
            AuditLogEventType::PackageDeleted => self::PackageDeleted,
            AuditLogEventType::VersionCreated => self::VersionCreated,
            AuditLogEventType::VersionReferenceChangeBlocked => self::VersionReferenceChangeBlocked,
            AuditLogEventType::VersionDeleted => self::VersionDeleted,
            AuditLogEventType::VersionSoftDeleted => self::VersionSoftDeleted,
            AuditLogEventType::VersionRecovered => self::VersionRecovered,
            AuditLogEventType::TwoFactorAuthenticationActivated => self::TwoFactorAuthenticationActivated,
            AuditLogEventType::TwoFactorAuthenticationDeactivated => self::TwoFactorAuthenticationDeactivated,
            AuditLogEventType::PasswordReset => self::PasswordReset,
            AuditLogEventType::PasswordChanged => self::PasswordChanged,
            AuditLogEventType::EmailChanged => self::EmailChanged,
            AuditLogEventType::GitHubLinkedWithUser => self::GitHubLinkedWithUser,
            AuditLogEventType::GitHubDisconnectedFromUser => self::GitHubDisconnectedFromUser,

            // Out of scope, each for its own reason.
            //
            // User lifecycle says nothing about a package, and a reset *request* comes from an
            // unauthenticated visitor, so publishing it would leak account existence (only the
            // completed reset is projected). A rename is the one open case: it is the mitigation
            // agreed for the log's frozen usernames, and projecting it is deferred, not rejected.
            AuditLogEventType::UserCreated, AuditLogEventType::UserVerified, AuditLogEventType::UserDeleted,
            AuditLogEventType::UserFrozen, AuditLogEventType::UserUnfrozen,
            AuditLogEventType::PasswordResetRequested, AuditLogEventType::UsernameChanged,
            // Moderation tooling: the filter list is not public, and its entries name packages we
            // have not published anything about.
            AuditLogEventType::FilterListEntryAdded, AuditLogEventType::FilterListEntryDeleted,
            AuditLogEventType::FilterListEntryDisabled, AuditLogEventType::FilterListEntryEnabled,
            AuditLogEventType::FilterListEntryEdited,
            // Advisories have their own public feed and API, which is the record for them.
            AuditLogEventType::SecurityAdvisoryCreated, AuditLogEventType::SecurityAdvisoryEdited,
            AuditLogEventType::SecurityAdvisoryWithdrawn,
            // Organization internals are not package events, and an org may not want its membership
            // public; invitations additionally carry the invited email. Org-owned packages are
            // deliberately out of scope for the fan-out for the same reason.
            AuditLogEventType::OrganizationCreated, AuditLogEventType::OrganizationNameChanged,
            AuditLogEventType::OrganizationSlugChanged, AuditLogEventType::OrganizationTeamCreated,
            AuditLogEventType::OrganizationTeamRenamed, AuditLogEventType::OrganizationTeamDeleted,
            AuditLogEventType::OrganizationTeamMemberAdded, AuditLogEventType::OrganizationTeamMemberRemoved,
            AuditLogEventType::OrganizationMemberJoined, AuditLogEventType::OrganizationMemberRemoved,
            AuditLogEventType::OrganizationMemberLeft,
            AuditLogEventType::OrganizationInvitationSent, AuditLogEventType::OrganizationInvitationResent,
            AuditLogEventType::OrganizationInvitationRevoked, AuditLogEventType::OrganizationInvitationAccepted,
            AuditLogEventType::OrganizationInvitationDeclined, AuditLogEventType::OrganizationInvitationExpired => null,
        };
    }

    /**
     * Account-security events carry no package of their own; the projector fans them out to every
     * package the affected user is a direct maintainer of, see
     * {@see \App\Entity\PackageRepository::getPackageRefsByMaintainer()}.
     *
     * Organization-owned packages are deliberately out of scope for now
     */
    public function fansOutToMaintainedPackages(): bool
    {
        return match ($this) {
            self::TwoFactorAuthenticationActivated, self::TwoFactorAuthenticationDeactivated,
            self::PasswordReset, self::PasswordChanged,
            self::EmailChanged, self::GitHubLinkedWithUser, self::GitHubDisconnectedFromUser => true,
            default => false,
        };
    }

    /**
     * The set of internal audit record types that are projected into the package transparency log.
     *
     * @return list<AuditLogEventType>
     */
    public static function projectedAuditLogEventTypes(): array
    {
        return array_values(array_filter(
            AuditLogEventType::cases(),
            static fn (AuditLogEventType $type): bool => self::fromAuditLogEventType($type) !== null,
        ));
    }

    /**
     * @return list<self>
     */
    public static function temporarilyHiddenTypes(): array
    {
        return [self::TwoFactorAuthenticationActivated, self::TwoFactorAuthenticationDeactivated];
    }

    /**
     * The subset of {@see self::projectedAuditLogEventTypes()} whose source row already carries the
     * package it belongs to, so it projects 1:1 with no fan-out. These events can be safely backfilled from audit log
     *
     * @return list<AuditLogEventType>
     */
    public static function packageNativeAuditLogEventTypes(): array
    {
        return array_values(array_filter(
            self::projectedAuditLogEventTypes(),
            static fn (AuditLogEventType $type): bool => self::fromAuditLogEventType($type)?->fansOutToMaintainedPackages() === false,
        ));
    }
}
