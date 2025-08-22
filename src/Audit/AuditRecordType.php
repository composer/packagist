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
    case AddMaintainer = 'add_maintainer'; // TODO
    case RemoveMaintainer = 'remove_maintainer'; // TODO
    case TransferPackage = 'transfer_package'; // TODO

    // package management
    case PackageCreated = 'package_created';
    case PackageDeleted = 'package_deleted';
    case CanonicalUrlChange = 'canonical_url_change';
    case VersionDeleted = 'version_deleted';
    case VersionReferenceChange = 'version_reference_change';
    case PackageAbandoned = 'package_abandoned'; // TODO
    case PackageUnabandoned = 'package_unabandoned'; // TODO

    // user management
    case UserCreated = 'user_created'; // TODO
    case UserDeleted = 'user_deleted'; // TODO
    case PasswordResetRequest = 'password_reset_request'; // TODO
    case PasswordReset = 'password_reset'; // TODO
    case PasswordChange = 'password_change'; // TODO
    case EmailChange = 'email_change'; // TODO
    case UsernameChange = 'username_change'; // TODO
    case GitHubLinkedWithUser = 'github_linked_with_user'; // TODO
    case GitHubDisconnectedFromUser = 'github_disconnected_from_user'; // TODO
    case TwoFaActivated = 'two_fa_activated'; // TODO
    case TwoFaDeactivated = 'two_fa_deactivated'; // TODO
}
