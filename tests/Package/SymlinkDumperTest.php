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

use App\Package\SymlinkDumper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SymlinkDumperTest extends TestCase
{
    private $mockDumper;

    protected function setUp(): void
    {
        $this->mockDumper = $this->createMock(SymlinkDumper::class);
    }

    protected function tearDown(): void
    {
        $this->mockDumper = null;
    }

    #[DataProvider('getTestGetTargetListingBlocks')]
    public function testGetTargetListingBlocks($now, array $expected): void
    {
        $blocks = self::invoke($this->mockDumper, 'getTargetListingBlocks', $now);

        $blocks = array_map(static function ($timestamp) {
            return date('Y-m-d', $timestamp);
        }, $blocks);

        $this->assertEquals($expected, $blocks);
    }

    public static function getTestGetTargetListingBlocks(): array
    {
        return [
            [
                strtotime('2014-12-31'),
                [
                    'latest' => '2014-12-22',
                    '2014-10' => '2014-10-01',
                    '2014-07' => '2014-07-01',
                    '2014-04' => '2014-04-01',
                    '2014-01' => '2014-01-01',
                    '2013' => '2013-01-01',
                ],
            ],
            [
                strtotime('2015-01-01'),
                [
                    'latest' => '2014-12-22',
                    '2015-01' => '2015-01-01',
                    '2014-10' => '2014-10-01',
                    '2014-07' => '2014-07-01',
                    '2014-04' => '2014-04-01',
                    '2014' => '2014-01-01',
                    '2013' => '2013-01-01',
                ],
            ],
            [
                strtotime('2015-05-31'),
                [
                    'latest' => '2015-05-18',
                    '2015-04' => '2015-04-01',
                    '2015-01' => '2015-01-01',
                    '2014-10' => '2014-10-01',
                    '2014-07' => '2014-07-01',
                    '2014' => '2014-01-01',
                    '2013' => '2013-01-01',
                ],
            ],
        ];
    }

    private static function invoke($object, $method): mixed
    {
        $refl = new \ReflectionMethod($object, $method);

        $args = \func_get_args();
        array_shift($args); // object
        array_shift($args); // method

        return $refl->invokeArgs($object, $args);
    }
}
