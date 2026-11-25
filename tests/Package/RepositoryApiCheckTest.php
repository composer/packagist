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

use App\Package\RepositoryApiCheck;
use App\Package\RepositoryApiState;
use PHPUnit\Framework\TestCase;

class RepositoryApiCheckTest extends TestCase
{
    public function testConstructionMissing(): void
    {
        // Arrange
        $state = RepositoryApiState::Missing;
        $statusCode = 404;

        // Act
        $check = new RepositoryApiCheck($state, $statusCode);

        // Assert
        self::assertSame(RepositoryApiState::Missing, $check->state);
        self::assertSame(404, $check->statusCode);
    }

    public function testConstructionExistsWithoutStatus(): void
    {
        // Arrange
        $state = RepositoryApiState::Exists;

        // Act
        $check = new RepositoryApiCheck($state);

        // Assert
        self::assertSame(RepositoryApiState::Exists, $check->state);
        self::assertNull($check->statusCode);
    }

    public function testConstructionUnknown(): void
    {
        // Arrange
        $state = RepositoryApiState::Unknown;

        // Act
        $check = new RepositoryApiCheck($state, 500);

        // Assert
        self::assertSame(RepositoryApiState::Unknown, $check->state);
        self::assertSame(500, $check->statusCode);
    }

    public function testEnumCases(): void
    {
        // Arrange / Act / Assert
        self::assertCount(3, RepositoryApiState::cases());
        self::assertContains(RepositoryApiState::Missing, RepositoryApiState::cases());
        self::assertContains(RepositoryApiState::Exists, RepositoryApiState::cases());
        self::assertContains(RepositoryApiState::Unknown, RepositoryApiState::cases());
    }
}
