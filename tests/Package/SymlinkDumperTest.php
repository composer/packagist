<?php

namespace App\Tests\Package;

use PHPUnit\Framework\TestCase;
use App\Package\SymlinkDumper;

class SymlinkDumperTest extends TestCase
{
    private $mockDumper;

    public function setUp(): void
    {
        $this->mockDumper = $this->getMockBuilder(SymlinkDumper::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function tearDown(): void
    {
        $this->mockDumper = null;
    }

    /**
     * @dataProvider getTestGetTargetListingBlocks
     */
    public function testGetTargetListingBlocks($now, array $expected)
    {
        $blocks = self::invoke($this->mockDumper, 'getTargetListingBlocks', $now);

        $blocks = array_map(function($timestamp) { return date('Y-m-d', $timestamp); }, $blocks);

        $this->assertEquals($expected, $blocks);
    }

    public function getTestGetTargetListingBlocks()
    {
        return array(
            array(
                strtotime('2014-12-31'),
                array(
                    'latest'  => '2014-12-22',
                    '2014-10' => '2014-10-01',
                    '2014-07' => '2014-07-01',
                    '2014-04' => '2014-04-01',
                    '2014-01' => '2014-01-01',
                    '2013'    => '2013-01-01',
                ),
            ),
            array(
                strtotime('2015-01-01'),
                array(
                    'latest'  => '2014-12-22',
                    '2015-01' => '2015-01-01',
                    '2014-10' => '2014-10-01',
                    '2014-07' => '2014-07-01',
                    '2014-04' => '2014-04-01',
                    '2014'    => '2014-01-01',
                    '2013'    => '2013-01-01',
                ),
            ),
            array(
                strtotime('2015-05-31'),
                array(
                    'latest'  => '2015-05-18',
                    '2015-04' => '2015-04-01',
                    '2015-01' => '2015-01-01',
                    '2014-10' => '2014-10-01',
                    '2014-07' => '2014-07-01',
                    '2014'    => '2014-01-01',
                    '2013'    => '2013-01-01',
                ),
            ),
        );
    }

    private static function invoke($object, $method)
    {
        $refl = new \ReflectionMethod($object, $method);
        $refl->setAccessible(true);

        $args = func_get_args();
        array_shift($args); // object
        array_shift($args); // method

        return $refl->invokeArgs($object, $args);
    }
}
