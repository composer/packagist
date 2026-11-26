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

use App\Util\HttpDownloaderOptionsFactory;
use PHPUnit\Framework\TestCase;

class HttpDownloaderOptionsFactoryTest extends TestCase
{
    public function testGetOptionsStructure(): void
    {
        // Arrange
        // (static call)

        // Act
        $options = HttpDownloaderOptionsFactory::getOptions();

        // Assert
        self::assertArrayHasKey('http', $options);
        self::assertArrayHasKey('header', $options['http']);
        self::assertContains('User-Agent: Packagist.org', $options['http']['header']);
        self::assertArrayHasKey('prevent_ip_access_callable', $options);
        self::assertIsCallable($options['prevent_ip_access_callable']);
        self::assertArrayHasKey('max_file_size', $options);
        self::assertSame(128_000_000, $options['max_file_size']);
    }

    public function testPreventIpAccessCallableBlocksPrivate(): void
    {
        // Arrange
        $options = HttpDownloaderOptionsFactory::getOptions();
        $callable = $options['prevent_ip_access_callable'];

        // Act
        $blocksPrivate = $callable('10.0.0.1');
        $blocksLoopback = $callable('127.0.0.1');
        $allowsPublic = $callable('8.8.8.8');

        // Assert
        self::assertTrue($blocksPrivate);
        self::assertTrue($blocksLoopback);
        self::assertFalse($allowsPublic);
    }
}
