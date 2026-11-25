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

use App\Util\UserAgentParser;
use PHPUnit\Framework\TestCase;

class UserAgentParserTest extends TestCase
{
    public function testNullUserAgent(): void
    {
        // Arrange
        $ua = null;

        // Act
        $parser = new UserAgentParser($ua);

        // Assert
        self::assertNull($parser->getComposerVersion());
        self::assertNull($parser->getPhpVersion());
        self::assertNull($parser->getOs());
        self::assertNull($parser->getCI());
        self::assertNull($parser->getCommand());
    }

    public function testEmptyUserAgent(): void
    {
        // Arrange
        $ua = '';

        // Act
        $parser = new UserAgentParser($ua);

        // Assert
        self::assertNull($parser->getComposerVersion());
        self::assertNull($parser->getPhpVersion());
    }

    public function testBasicComposerUa(): void
    {
        // Arrange
        $ua = 'Composer/2.7.7 (Linux; 6.5.0; PHP 8.3.0; curl 8.5)';

        // Act
        $parser = new UserAgentParser($ua);

        // Assert
        self::assertSame('2.7.7', $parser->getComposerVersion());
        self::assertSame('8.3.0', $parser->getPhpVersion());
        self::assertSame('linux', $parser->getOs());
        self::assertSame('curl 8.5', $parser->getHttpVersion());
        self::assertFalse($parser->getCI());
        self::assertNull($parser->getAiAgent());
        self::assertNull($parser->getCommand());
    }

    public function testWithCiAndCmd(): void
    {
        // Arrange
        $ua = 'Composer/2.8.0 (Darwin; 23.0.0; PHP 8.4.1; curl 8.7.1; CI; cmd:update)';

        // Act
        $parser = new UserAgentParser($ua);

        // Assert
        self::assertSame('2.8.0', $parser->getComposerVersion());
        self::assertSame('8.4.1', $parser->getPhpVersion());
        self::assertSame('darwin', $parser->getOs());
        self::assertTrue($parser->getCI());
        self::assertSame('update', $parser->getCommand());
        self::assertNull($parser->getRunningOperation());
    }

    public function testWithCmdAndOperation(): void
    {
        // Arrange
        $ua = 'Composer/2.8.0 (Linux; 6.1.0; PHP 8.3.12; streams; cmd:require,update)';

        // Act
        $parser = new UserAgentParser($ua);

        // Assert
        self::assertSame('require', $parser->getCommand());
        self::assertSame('update', $parser->getRunningOperation());
        self::assertSame('streams', $parser->getHttpVersion());
    }

    public function testPhpMinorVersion(): void
    {
        // Arrange
        $ua = 'Composer/2.7.0 (Linux; 6.1.0; PHP 8.3.12)';

        // Act
        $parser = new UserAgentParser($ua);

        // Assert
        self::assertSame('8.3', $parser->getPhpMinorVersion());
    }

    public function testInvalidUaLeavesNulls(): void
    {
        // Arrange
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

        // Act
        $parser = new UserAgentParser($ua);

        // Assert
        self::assertNull($parser->getComposerVersion());
        self::assertNull($parser->getPhpVersion());
        self::assertNull($parser->getOs());
    }

    public function testSourceComposerVersionNormalized(): void
    {
        // Arrange
        $ua = 'Composer/source (Linux; 6.1.0; PHP 8.2.0)';

        // Act
        $parser = new UserAgentParser($ua);

        // Assert
        self::assertSame('pre-1.8.5', $parser->getComposerVersion());
    }
}
