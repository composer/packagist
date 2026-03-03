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
    case PackageDeleted = 'package_deleted';

    // version
    case VersionCreated = 'version_created';
    case VersionReferenceChanged = 'version_reference_changed';
    case VersionDeleted = 'version_deleted';

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

    public function category(): string
    {
        return match($this) {
            self::MaintainerAdded, self::MaintainerRemoved, self::PackageTransferred
                => 'ownership',
            self::PackageCreated, self::PackageDeleted, self::CanonicalUrlChanged,
            self::PackageAbandoned, self::PackageUnabandoned
                => 'package',
            self::VersionCreated, self::VersionDeleted, self::VersionReferenceChanged
                => 'version',
            self::UserCreated, self::UserVerified, self::UserDeleted,
            self::PasswordResetRequested, self::PasswordReset, self::PasswordChanged,
            self::EmailChanged, self::UsernameChanged, self::GitHubLinkedWithUser,
            self::GitHubDisconnectedFromUser, self::TwoFaAuthenticationActivated,
            self::TwoFaAuthenticationDeactivated
                => 'user',
            self::FilterListEntryAdded, self::FilterListEntryDeleted
                => 'filterlist',
        };
    }
}
