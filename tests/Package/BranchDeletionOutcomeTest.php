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

use App\Package\BranchDeletionOutcome;
use PHPUnit\Framework\TestCase;

class BranchDeletionOutcomeTest extends TestCase
{
    public function testEnumValues(): void
    {
        // Arrange / Act / Assert
        self::assertSame('removed', BranchDeletionOutcome::Removed->value);
        self::assertSame('nothing_to_remove', BranchDeletionOutcome::NothingToRemove->value);
        self::assertSame('deferred', BranchDeletionOutcome::Deferred->value);
    }

    public function testCasesCount(): void
    {
        // Arrange / Act / Assert
        self::assertCount(3, BranchDeletionOutcome::cases());
    }

    public function testFromValue(): void
    {
        // Arrange
        $value = 'removed';

        // Act
        $outcome = BranchDeletionOutcome::from($value);

        // Assert
        self::assertSame(BranchDeletionOutcome::Removed, $outcome);
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        // Arrange
        $invalid = 'unknown';

        // Act
        $outcome = BranchDeletionOutcome::tryFrom($invalid);

        // Assert
        self::assertNull($outcome);
    }
}
