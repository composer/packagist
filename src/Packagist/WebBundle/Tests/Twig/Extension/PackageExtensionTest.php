<?php

namespace Packagist\WebBundle\Test\Twig\Extension;

use Packagist\WebBundle\Twig\Extension\PackageExtension;

class PackageExtensionTest extends \PHPUnit_Framework_TestCase
{
    public function testMatch()
    {
        $extension = new PackageExtension();

        $value = 'vendor/test-name';

        $this->assertTrue($extension->match($value));
    }

    public function testMatchPhp()
    {
        $extension = new PackageExtension();

        $value = 'php';

        $this->assertFalse($extension->match($value));
    }

    public function testMatchPhpExtension()
    {
        $extension = new PackageExtension();

        $value = 'ext-zip';

        $this->assertFalse($extension->match($value));
    }
}
