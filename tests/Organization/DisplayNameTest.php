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

namespace App\Tests\Organization;

use App\Organization\Domain\DisplayName;
use App\Organization\Domain\Exception\InvalidDisplayNameException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DisplayNameTest extends TestCase
{
    public function testConstructAcceptsValidValue(): void
    {
        self::assertSame('ACME Corp', (new DisplayName('ACME Corp'))->value);
    }

    #[DataProvider('invalidDisplayNames')]
    public function testConstructRejectsInvalidValue(string $displayName): void
    {
        $this->expectException(InvalidDisplayNameException::class);

        new DisplayName($displayName);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDisplayNames(): iterable
    {
        yield 'empty' => [''];
        yield 'punctuation' => ['ACME, Inc.'];
        yield 'symbols' => ['ACME @ Corp'];
        yield 'too long' => [str_repeat('a', 61)];
    }

    public function testFromUserInputTrimsWhitespace(): void
    {
        self::assertSame('ACME Corp', DisplayName::fromUserInput('  ACME Corp  ')->value);
    }

    public function testFromUserInputStillRejectsForbiddenCharacters(): void
    {
        // User input should still be properly validated after being trimmed
        $this->expectException(InvalidDisplayNameException::class);

        DisplayName::fromUserInput('  ACME, Inc.  ');
    }
}
