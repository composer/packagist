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

namespace App\Tests\Package;

use App\Package\UpdateFailureKind;
use App\Package\UpdateFailureVerdict;
use PHPUnit\Framework\TestCase;

class UpdateFailureVerdictTest extends TestCase
{
    public function testConstructionWithDefaults(): void
    {
        // Arrange
        $kind = UpdateFailureKind::Gone;
        $code = 'git_remote_repository_not_found';

        // Act
        $verdict = new UpdateFailureVerdict($kind, $code);

        // Assert
        self::assertSame(UpdateFailureKind::Gone, $verdict->kind);
        self::assertSame('git_remote_repository_not_found', $verdict->code);
        self::assertFalse($verdict->escalatesToGone);
    }

    public function testConstructionWithEscalateFlag(): void
    {
        // Arrange
        $kind = UpdateFailureKind::Unreachable;
        $code = 'host_unreachable';

        // Act
        $verdict = new UpdateFailureVerdict($kind, $code, true);

        // Assert
        self::assertSame(UpdateFailureKind::Unreachable, $verdict->kind);
        self::assertSame('host_unreachable', $verdict->code);
        self::assertTrue($verdict->escalatesToGone);
    }

    public function testEnumCasesExist(): void
    {
        // Arrange / Act / Assert
        self::assertInstanceOf(UpdateFailureKind::class, UpdateFailureKind::Gone);
        self::assertInstanceOf(UpdateFailureKind::class, UpdateFailureKind::Unreachable);
        self::assertCount(2, UpdateFailureKind::cases());
    }
}
