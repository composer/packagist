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

namespace App\Tests\Entity;

use App\Entity\Package;
use App\Entity\Tag;
use App\Entity\Version;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('providePackageScenarios')]
    public function testInstallCommand(string $type, string $tag, string $expected): void
    {
        $version = new Version();
        $version->addTag(new Tag('dev'));

        $package = new Package();
        $package->setName('vendor/name');
        $package->setType('project');
        $package->addVersion($version);

        self::assertSame('composer create-project vendor/name', $package->getInstallCommand());
    }

    public static function providePackageScenarios(): array
    {
        return [
            'project' => ['project', 'dev', 'composer create-project vendor/name'],
            'library non-dev' => ['library', 'database', 'composer require vendor/name'],
            'library dev' => ['library', 'testing', 'composer require --dev vendor/name'],
        ];
    }

    public function testInstallCommandWithoutVersion(): void
    {
        $package = new Package();
        $package->setName('vendor/name');

        self::assertSame('composer require vendor/name', $package->getInstallCommand());
    }
}
