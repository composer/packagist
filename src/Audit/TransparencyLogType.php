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

/**
 * Holds two kinds of projected event:
 *  - package-native events (ownership / package / version): projected 1:1 from an audit row that
 *    already carries the packageId.
 *  - account events ({@see self::fansOutToMaintainedPackages()}): user-security events that carry no package. The
 *    projector fans each of these out to every package the user maintains, producing one entry per
 *    package.
 */
enum TransparencyLogType: string
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
    case TwoFaActivated = 'two_fa_activated';
    case TwoFaDeactivated = 'two_fa_deactivated';
    case PasswordReset = 'password_reset';
    case PasswordChanged = 'password_changed';
    case PasswordResetRequested = 'password_reset_requested';
    case EmailChanged = 'email_changed';
    case GitHubLinkedWithUser = 'github_linked_with_user';
    case GitHubDisconnectedFromUser = 'github_disconnected_from_user';

    /**
     * Maps an internal audit record type onto its public transparency-log type, or null when the
     * event is out of scope for the package transparency log.
     */
    public static function fromAuditRecordType(AuditRecordType $type): ?self
    {
        return match ($type) {
            AuditRecordType::MaintainerAdded => self::MaintainerAdded,
            AuditRecordType::MaintainerRemoved => self::MaintainerRemoved,
            AuditRecordType::PackageTransferred => self::PackageTransferred,
            AuditRecordType::PackageCreated => self::PackageCreated,
            AuditRecordType::CanonicalUrlChanged => self::CanonicalUrlChanged,
            AuditRecordType::PackageAbandoned => self::PackageAbandoned,
            AuditRecordType::PackageUnabandoned => self::PackageUnabandoned,
            AuditRecordType::PackageFrozen => self::PackageFrozen,
            AuditRecordType::PackageUnfrozen => self::PackageUnfrozen,
            AuditRecordType::PackageDeleted => self::PackageDeleted,
            AuditRecordType::VersionCreated => self::VersionCreated,
            AuditRecordType::VersionReferenceChangeBlocked => self::VersionReferenceChangeBlocked,
            AuditRecordType::VersionDeleted => self::VersionDeleted,
            AuditRecordType::VersionSoftDeleted => self::VersionSoftDeleted,
            AuditRecordType::VersionRecovered => self::VersionRecovered,
            AuditRecordType::TwoFaAuthenticationActivated => self::TwoFaActivated,
            AuditRecordType::TwoFaAuthenticationDeactivated => self::TwoFaDeactivated,
            AuditRecordType::PasswordReset => self::PasswordReset,
            AuditRecordType::PasswordChanged => self::PasswordChanged,
            AuditRecordType::PasswordResetRequested => self::PasswordResetRequested,
            AuditRecordType::EmailChanged => self::EmailChanged,
            AuditRecordType::GitHubLinkedWithUser => self::GitHubLinkedWithUser,
            AuditRecordType::GitHubDisconnectedFromUser => self::GitHubDisconnectedFromUser,
            default => null,
        };
    }

    /**
     * Account-security events carry no package of their own; the projector fans them out to every
     * package the affected user maintains (direct maintainer or via an owning organization).
     */
    public function fansOutToMaintainedPackages(): bool
    {
        return match ($this) {
            self::TwoFaActivated, self::TwoFaDeactivated,
            self::PasswordReset, self::PasswordChanged, self::PasswordResetRequested,
            self::EmailChanged, self::GitHubLinkedWithUser, self::GitHubDisconnectedFromUser => true,
            default => false,
        };
    }

    /**
     * The set of internal audit record types that are projected into the package transparency log.
     *
     * @return list<AuditRecordType>
     */
    public static function projectedAuditRecordTypes(): array
    {
        return array_values(array_filter(
            AuditRecordType::cases(),
            static fn (AuditRecordType $type): bool => self::fromAuditRecordType($type) !== null,
        ));
    }

    /**
     * The subset of {@see self::projectedAuditRecordTypes()} whose source row already carries the
     * package it belongs to, so it projects 1:1 with no fan-out. These events can be safely backfilled from audit log
     *
     * @return list<AuditRecordType>
     */
    public static function packageNativeAuditRecordTypes(): array
    {
        return array_values(array_filter(
            self::projectedAuditRecordTypes(),
            static fn (AuditRecordType $type): bool => self::fromAuditRecordType($type)?->fansOutToMaintainedPackages() === false,
        ));
    }
}
