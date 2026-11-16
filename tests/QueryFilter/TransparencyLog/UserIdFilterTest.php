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

namespace App\Tests\QueryFilter\TransparencyLog;

use App\QueryFilter\TransparencyLog\UserIdFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

class UserIdFilterTest extends TestCase
{
    public function testAcceptsAPositiveInteger(): void
    {
        self::assertSame(42, UserIdFilter::fromQuery(new InputBag(['user_id' => '42']))->getSelectedValue());
    }

    #[DataProvider('invalidIds')]
    public function testIgnoresAnythingElse(string $value): void
    {
        self::assertNull(UserIdFilter::fromQuery(new InputBag(['user_id' => $value]))->getSelectedValue());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'decimal' => ['12.7'];
        yield 'exponent' => ['1e3'];
        yield 'whitespace' => [' 5'];
        yield 'hex' => ['0x1A'];
        yield 'text' => ['not-an-id'];
    }

    public function testMissingParameterIsIgnored(): void
    {
        self::assertNull(UserIdFilter::fromQuery(new InputBag())->getSelectedValue());
    }
}
