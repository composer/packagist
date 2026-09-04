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

namespace App\Tests\QueryFilter;

use App\QueryFilter\LikePattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LikePatternTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function values(): iterable
    {
        yield 'plain value is untouched' => ['alice', 'alice'];
        yield 'single char wildcard' => ['a_b', 'a\_b'];
        yield 'multi char wildcard' => ['100%', '100\%'];
        yield 'escape character' => ['back\\slash', 'back\\\\slash'];
        yield 'trailing escape character' => ['alice\\', 'alice\\\\'];
        yield 'all metacharacters' => ['%_\\', '\%\_\\\\'];
    }

    #[DataProvider('values')]
    public function testEscapeNeutralisesMetacharacters(string $value, string $expected): void
    {
        self::assertSame($expected, LikePattern::escape($value));
    }

    #[DataProvider('values')]
    public function testContainsWrapsTheEscapedValue(string $value, string $expected): void
    {
        self::assertSame('%'.$expected.'%', LikePattern::contains($value));
    }
}
