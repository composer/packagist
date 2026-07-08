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

use App\Organization\Domain\Exception\InvalidSlugException;
use App\Organization\Domain\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SlugTest extends TestCase
{
    public function testConstructAcceptsCanonicalValue(): void
    {
        self::assertSame('acme-corp', (new Slug('acme-corp'))->value);
    }

    public function testConstructCanonicalisesCaseAndWhitespace(): void
    {
        self::assertSame('acme', (new Slug('  ACME  '))->value);
    }

    #[DataProvider('invalidSlugs')]
    public function testConstructRejectsInvalidValue(string $slug): void
    {
        $this->expectException(InvalidSlugException::class);

        new Slug($slug);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSlugs(): iterable
    {
        yield 'empty' => [''];
        yield 'leading hyphen' => ['-acme'];
        yield 'trailing hyphen' => ['acme-'];
        yield 'spaces' => ['ac me'];
        yield 'underscore' => ['ac_me'];
        yield 'too long' => [str_repeat('a', 21)];
    }
}
