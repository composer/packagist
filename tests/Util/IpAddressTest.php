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

namespace App\Tests\Util;

use App\Util\IpAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IpAddressTest extends TestCase
{
    public function testStringToBinaryWithNull(): void
    {
        // Arrange
        $value = null;

        // Act
        $result = IpAddress::stringToBinary($value);

        // Assert
        self::assertNull($result);
    }

    public function testBinaryToStringWithNull(): void
    {
        // Arrange
        $value = null;

        // Act
        $result = IpAddress::binaryToString($value);

        // Assert
        self::assertNull($result);
    }

    #[DataProvider('provideValidIps')]
    public function testRoundTrip(string $ip): void
    {
        // Arrange
        // (ip provided)

        // Act
        $binary = IpAddress::stringToBinary($ip);
        $restored = IpAddress::binaryToString($binary);

        // Assert
        self::assertNotNull($binary);
        self::assertSame($ip, $restored);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValidIps(): iterable
    {
        yield 'ipv4 loopback' => ['127.0.0.1'];
        yield 'ipv4 public' => ['8.8.8.8'];
        yield 'ipv4 zero' => ['0.0.0.0'];
        yield 'ipv6 loopback' => ['::1'];
        yield 'ipv6 full' => ['2001:db8::1'];
    }

    public function testStringToBinaryThrowsOnInvalid(): void
    {
        // Arrange
        $invalid = 'not-an-ip';

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid IP address: not-an-ip');

        IpAddress::stringToBinary($invalid);
    }

    public function testBinaryToStringThrowsOnInvalid(): void
    {
        // Arrange
        $invalidBinary = 'not-binary-ip';

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid binary IP address stored in database');

        IpAddress::binaryToString($invalidBinary);
    }
}
