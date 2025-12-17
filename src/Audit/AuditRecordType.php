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
    case PackageDeleted = 'package_deleted';
    case CanonicalUrlChanged = 'canonical_url_changed';
    case VersionCreated = 'version_created';
    case VersionDeleted = 'version_deleted';

    case VersionReferenceChanged = 'version_reference_changed';
    case PackageAbandoned = 'package_abandoned';
    case PackageUnabandoned = 'package_unabandoned';

    // user management
    case UserCreated = 'user_created';
    case UserVerified = 'user_verified';
    case UserDeleted = 'user_deleted';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordReset = 'password_reset';
    case PasswordChanged = 'password_changed';
    case EmailChanged = 'email_changed';
    case UsernameChanged = 'username_changed';
    case GitHubLinkedWithUser = 'github_linked_with_user'; // TODO
    case GitHubDisconnectedFromUser = 'github_disconnected_from_user'; // TODO
    case TwoFaAuthenticationActivated = 'two_fa_activated';
    case TwoFaAuthenticationDeactivated = 'two_fa_deactivated';
}
