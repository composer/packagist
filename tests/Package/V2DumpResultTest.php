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

use App\Package\V2DumpOutcome;
use App\Package\V2DumpResult;
use PHPUnit\Framework\TestCase;

class V2DumpResultTest extends TestCase
{
    public function testCompleteWithNoStale(): void
    {
        // Arrange
        $outcome = V2DumpOutcome::Complete;
        $leftStale = [];

        // Act
        $result = new V2DumpResult($outcome, $leftStale);

        // Assert
        self::assertSame(V2DumpOutcome::Complete, $result->outcome);
        self::assertSame([], $result->leftStale);
    }

    public function testSomeFailedWithIds(): void
    {
        // Arrange
        $outcome = V2DumpOutcome::SomeFailed;
        $leftStale = [42, 99, 1001];

        // Act
        $result = new V2DumpResult($outcome, $leftStale);

        // Assert
        self::assertSame(V2DumpOutcome::SomeFailed, $result->outcome);
        self::assertSame([42, 99, 1001], $result->leftStale);
    }

    public function testAborted(): void
    {
        // Arrange
        $outcome = V2DumpOutcome::Aborted;
        $leftStale = [7];

        // Act
        $result = new V2DumpResult($outcome, $leftStale);

        // Assert
        self::assertSame(V2DumpOutcome::Aborted, $result->outcome);
        self::assertCount(1, $result->leftStale);
    }

    public function testEnumCases(): void
    {
        // Arrange / Act / Assert
        self::assertCount(3, V2DumpOutcome::cases());
        self::assertContains(V2DumpOutcome::Complete, V2DumpOutcome::cases());
        self::assertContains(V2DumpOutcome::SomeFailed, V2DumpOutcome::cases());
        self::assertContains(V2DumpOutcome::Aborted, V2DumpOutcome::cases());
    }
}
