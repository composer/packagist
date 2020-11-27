<?php declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Package;
use PHPUnit\Framework\TestCase;

class PackageTest extends TestCase
{
    public function testWasUpdatedInTheLast24Hours(): void
    {
        $package = new Package();
        $this->assertFalse($package->wasUpdatedInTheLast24Hours());

        $package->setUpdatedAt(new \DateTime('2019-01-01'));
        $this->assertFalse($package->wasUpdatedInTheLast24Hours());

        $package->setUpdatedAt(new \DateTime('now'));
        $this->assertTrue($package->wasUpdatedInTheLast24Hours());
    }
}
