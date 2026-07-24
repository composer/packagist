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

namespace App\Audit;

enum AuditRecordType: string
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

    // user management
    case UserCreated = 'user_created';
    case UserVerified = 'user_verified';
    case UserDeleted = 'user_deleted';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordReset = 'password_reset';
    case PasswordChanged = 'password_changed';
    case EmailChanged = 'email_changed';
    case UsernameChanged = 'username_changed';
    case GitHubLinkedWithUser = 'github_linked_with_user';
    case GitHubDisconnectedFromUser = 'github_disconnected_from_user';
    case TwoFaAuthenticationActivated = 'two_fa_activated';
    case TwoFaAuthenticationDeactivated = 'two_fa_deactivated';

    // filterlist
    case FilterListEntryAdded = 'filter_list_entry_added';
    case FilterListEntryDeleted = 'filter_list_entry_deleted';
    case FilterListEntryDisabled = 'filter_list_entry_disabled';
    case FilterListEntryEnabled = 'filter_list_entry_enabled';
    case FilterListEntryEdited = 'filter_list_entry_edited';

    // security advisory
    case SecurityAdvisoryCreated = 'security_advisory_created';
    case SecurityAdvisoryEdited = 'security_advisory_edited';
    case SecurityAdvisoryWithdrawn = 'security_advisory_withdrawn';

    // organization
    case OrganizationCreated = 'organization_created';
    case OrganizationNameChanged = 'organization_name_changed';
    case OrganizationSlugChanged = 'organization_slug_changed';
    case OrganizationTeamCreated = 'organization_team_created';
    case OrganizationTeamRenamed = 'organization_team_renamed';
    case OrganizationTeamDeleted = 'organization_team_deleted';
    case OrganizationTeamMemberAdded = 'organization_team_member_added';
    case OrganizationTeamMemberRemoved = 'organization_team_member_removed';
    case OrganizationMemberRemoved = 'organization_member_removed';
    case OrganizationMemberLeft = 'organization_member_left';

    public function category(): string
    {
        return match ($this) {
            self::MaintainerAdded, self::MaintainerRemoved, self::PackageTransferred => 'ownership',
            self::PackageCreated, self::PackageDeleted, self::CanonicalUrlChanged,
            self::PackageAbandoned, self::PackageUnabandoned, self::PackageFrozen, self::PackageUnfrozen => 'package',
            self::VersionCreated, self::VersionDeleted,
            self::VersionReferenceChangeBlocked, self::VersionSoftDeleted, self::VersionRecovered => 'version',
            self::UserCreated, self::UserVerified, self::UserDeleted,
            self::PasswordResetRequested, self::PasswordReset, self::PasswordChanged,
            self::EmailChanged, self::UsernameChanged, self::GitHubLinkedWithUser,
            self::GitHubDisconnectedFromUser, self::TwoFaAuthenticationActivated,
            self::TwoFaAuthenticationDeactivated => 'user',
            self::FilterListEntryAdded, self::FilterListEntryDeleted,
            self::FilterListEntryDisabled, self::FilterListEntryEnabled,
            self::FilterListEntryEdited => 'filterlist',
            self::SecurityAdvisoryCreated, self::SecurityAdvisoryEdited,
            self::SecurityAdvisoryWithdrawn => 'advisory',
            self::OrganizationCreated, self::OrganizationNameChanged, self::OrganizationSlugChanged,
            self::OrganizationTeamCreated, self::OrganizationTeamRenamed, self::OrganizationTeamDeleted,
            self::OrganizationTeamMemberAdded, self::OrganizationTeamMemberRemoved,
            self::OrganizationMemberRemoved, self::OrganizationMemberLeft => 'organization',
        };
    }
}
