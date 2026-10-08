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

namespace App\Tests\Log;

use App\Log\AuditLogEventType;
use App\Log\TransparencyLogEventType;
use PHPUnit\Framework\TestCase;

class TransparencyLogEventTypeTest extends TestCase
{
    /**
     * @return list<AuditLogEventType>
     */
    private static function accountAuditTypes(): array
    {
        return [
            AuditLogEventType::TwoFactorAuthenticationActivated,
            AuditLogEventType::TwoFactorAuthenticationDeactivated,
            AuditLogEventType::PasswordReset,
            AuditLogEventType::PasswordChanged,
            AuditLogEventType::EmailChanged,
            AuditLogEventType::GitHubLinkedWithUser,
            AuditLogEventType::GitHubDisconnectedFromUser,
        ];
    }

    public function testPackageNativeEventsMapOneToOneAndAreNotAccountEvents(): void
    {
        foreach (AuditLogEventType::cases() as $type) {
            if (!\in_array($type->category(), ['ownership', 'package', 'version'], true)) {
                continue;
            }

            $mapped = TransparencyLogEventType::fromAuditLogEventType($type);
            self::assertNotNull($mapped, $type->value.' should be projectable');
            self::assertSame($type->value, $mapped->value);
            self::assertFalse($mapped->fansOutToMaintainedPackages(), $type->value.' is package-native, not an account event');
        }
    }

    public function testAccountSecurityEventsMapAndAreAccountEvents(): void
    {
        foreach (self::accountAuditTypes() as $type) {
            $mapped = TransparencyLogEventType::fromAuditLogEventType($type);
            self::assertNotNull($mapped, $type->value.' should be projectable');
            self::assertTrue($mapped->fansOutToMaintainedPackages(), $type->value.' should fan out as an account event');
        }
    }

    public function testNonProjectedEventsReturnNull(): void
    {
        foreach ([
            // user-category events that are NOT security-relevant enough to project
            AuditLogEventType::UserCreated,
            AuditLogEventType::UserVerified,
            AuditLogEventType::UserDeleted,
            AuditLogEventType::UserFrozen,
            AuditLogEventType::UserUnfrozen,
            AuditLogEventType::UsernameChanged,
            // a reset *request* comes from an unauthenticated visitor, so publishing it would leak
            // account existence; only the completed reset is projected
            AuditLogEventType::PasswordResetRequested,
            // other out-of-scope domains
            AuditLogEventType::SecurityAdvisoryCreated,
            AuditLogEventType::FilterListEntryAdded,
            AuditLogEventType::OrganizationCreated,
        ] as $type) {
            self::assertNull(TransparencyLogEventType::fromAuditLogEventType($type), $type->value.' must not be projected');
        }
    }

    public function testProjectedAuditLogEventTypesMatchesEnumCases(): void
    {
        $projected = TransparencyLogEventType::projectedAuditLogEventTypes();

        self::assertCount(\count(TransparencyLogEventType::cases()), $projected);
        foreach ($projected as $auditType) {
            self::assertNotNull(TransparencyLogEventType::fromAuditLogEventType($auditType));
        }
    }

    public function testPackageNativeAuditLogEventTypesIsTheProjectedSetWithoutAccountEvents(): void
    {
        $packageNative = TransparencyLogEventType::packageNativeAuditLogEventTypes();

        self::assertCount(\count(TransparencyLogEventType::projectedAuditLogEventTypes()) - \count(self::accountAuditTypes()), $packageNative);
        foreach ($packageNative as $auditType) {
            self::assertFalse(TransparencyLogEventType::fromAuditLogEventType($auditType)?->fansOutToMaintainedPackages());
        }
        foreach (self::accountAuditTypes() as $accountType) {
            self::assertNotContains($accountType, $packageNative, $accountType->value.' must not be backfillable');
        }
    }
}
