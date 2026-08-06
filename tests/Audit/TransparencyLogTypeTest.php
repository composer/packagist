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

namespace App\Tests\Audit;

use App\Audit\AuditRecordType;
use App\Audit\TransparencyLogType;
use PHPUnit\Framework\TestCase;

class TransparencyLogTypeTest extends TestCase
{
    /**
     * @return list<AuditRecordType>
     */
    private static function accountAuditTypes(): array
    {
        return [
            AuditRecordType::TwoFaAuthenticationActivated,
            AuditRecordType::TwoFaAuthenticationDeactivated,
            AuditRecordType::PasswordReset,
            AuditRecordType::PasswordChanged,
            AuditRecordType::PasswordResetRequested,
            AuditRecordType::EmailChanged,
            AuditRecordType::GitHubLinkedWithUser,
            AuditRecordType::GitHubDisconnectedFromUser,
        ];
    }

    public function testPackageNativeEventsMapOneToOneAndAreNotAccountEvents(): void
    {
        foreach (AuditRecordType::cases() as $type) {
            if (!\in_array($type->category(), ['ownership', 'package', 'version'], true)) {
                continue;
            }

            $mapped = TransparencyLogType::fromAuditRecordType($type);
            self::assertNotNull($mapped, $type->value.' should be projectable');
            self::assertSame($type->value, $mapped->value);
            self::assertFalse($mapped->fansOutToMaintainedPackages(), $type->value.' is package-native, not an account event');
        }
    }

    public function testAccountSecurityEventsMapAndAreAccountEvents(): void
    {
        foreach (self::accountAuditTypes() as $type) {
            $mapped = TransparencyLogType::fromAuditRecordType($type);
            self::assertNotNull($mapped, $type->value.' should be projectable');
            self::assertTrue($mapped->fansOutToMaintainedPackages(), $type->value.' should fan out as an account event');
        }
    }

    public function testNonProjectedEventsReturnNull(): void
    {
        foreach ([
            // user-category events that are NOT security-relevant enough to project
            AuditRecordType::UserCreated,
            AuditRecordType::UserVerified,
            AuditRecordType::UserDeleted,
            AuditRecordType::UserFrozen,
            AuditRecordType::UserUnfrozen,
            AuditRecordType::UsernameChanged,
            // other out-of-scope domains
            AuditRecordType::SecurityAdvisoryCreated,
            AuditRecordType::FilterListEntryAdded,
            AuditRecordType::OrganizationCreated,
        ] as $type) {
            self::assertNull(TransparencyLogType::fromAuditRecordType($type), $type->value.' must not be projected');
        }
    }

    public function testProjectedAuditRecordTypesMatchesEnumCases(): void
    {
        $projected = TransparencyLogType::projectedAuditRecordTypes();

        self::assertCount(\count(TransparencyLogType::cases()), $projected);
        foreach ($projected as $auditType) {
            self::assertNotNull(TransparencyLogType::fromAuditRecordType($auditType));
        }
    }

    public function testPackageNativeAuditRecordTypesIsTheProjectedSetWithoutAccountEvents(): void
    {
        $packageNative = TransparencyLogType::packageNativeAuditRecordTypes();

        self::assertCount(\count(TransparencyLogType::projectedAuditRecordTypes()) - \count(self::accountAuditTypes()), $packageNative);
        foreach ($packageNative as $auditType) {
            self::assertFalse(TransparencyLogType::fromAuditRecordType($auditType)?->fansOutToMaintainedPackages());
        }
        foreach (self::accountAuditTypes() as $accountType) {
            self::assertNotContains($accountType, $packageNative, $accountType->value.' must not be backfillable');
        }
    }
}
