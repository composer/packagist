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
 * The events published in the package transparency log. Two kinds:
 *  - package-native (ownership / package / version): projected 1:1 from an audit row that already
 *    has the packageId.
 *  - account events ({@see self::fansOutToMaintainedPackages()}): user-security events with no
 *    package, written to every package the user maintains, one entry each.
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
     * Maps an audit record type onto its transparency-log type, or null when it is out of scope.
     *
     * No default arm on purpose: this is the only way into the log
     * ({@see \App\Entity\PackageTransparencyLogQueueRepository::enqueue()}), so a new case in
     * {@see AuditLogEventType} fails here until someone maps it or lists it below as out of scope.
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
            // unauthenticated visitor, so publishing it would show that the account exists (the
            // completed reset is projected). Projecting renames is postponed, not rejected.
            AuditLogEventType::UserCreated, AuditLogEventType::UserVerified, AuditLogEventType::UserDeleted,
            AuditLogEventType::UserFrozen, AuditLogEventType::UserUnfrozen,
            AuditLogEventType::PasswordResetRequested, AuditLogEventType::UsernameChanged,
            // Moderation tooling: the filter list is not public, and its entries name packages we
            // have not published anything about.
            AuditLogEventType::FilterListEntryAdded, AuditLogEventType::FilterListEntryDeleted,
            AuditLogEventType::FilterListEntryDisabled, AuditLogEventType::FilterListEntryEnabled,
            AuditLogEventType::FilterListEntryEdited,
            // Advisories already have their own public feed and API.
            AuditLogEventType::SecurityAdvisoryCreated, AuditLogEventType::SecurityAdvisoryEdited,
            AuditLogEventType::SecurityAdvisoryWithdrawn,
            // Organization internals are not package events, an org may not want its membership
            // public, and invitations carry the invited email.
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
     * Account events have no package of their own, so the projector writes them to every package the
     * user directly maintains ({@see \App\Entity\PackageRepository::getPackageRefsByMaintainer()}).
     * Organization-owned packages are out of scope for now.
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
     * The audit record types that are projected into the package transparency log.
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
     * The subset of {@see self::projectedAuditLogEventTypes()} whose audit row already has a package,
     * so it projects 1:1 with no fan-out and can safely be backfilled from audit_log.
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
