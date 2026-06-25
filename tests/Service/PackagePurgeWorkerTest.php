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

namespace App\Tests\Service;

use App\Entity\Job;
use App\Entity\Package;
use App\Model\PackageManager;
use App\Model\ProviderManager;
use App\Service\PackagePurgeWorker;
use App\Tests\Fixtures\Fixtures;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Seld\Signal\SignalHandler;

class PackagePurgeWorkerTest extends TestCase
{
    use Fixtures;

    public function testPurgesArtifactsForExistingPackage(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');

        $providerManager = $this->createMock(ProviderManager::class);
        $providerManager->expects($this->once())->method('deletePackage')->with($package);

        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->expects($this->once())->method('deletePackageMetadata')->with('test/pkg');
        $packageManager->expects($this->once())->method('deletePackageCdnMetadata')->with('test/pkg');
        $packageManager->expects($this->once())->method('deletePackageSearchIndex')->with('test/pkg');

        $worker = new PackagePurgeWorker($this->mockRegistry($package), $providerManager, $packageManager);

        $result = $worker->process(new Job('id', 'package:purge', ['name' => 'test/pkg']), SignalHandler::create());

        self::assertSame(Job::STATUS_COMPLETED, $result['status']);
    }

    public function testStillPurgesArtifactsWhenPackageAlreadyGone(): void
    {
        $providerManager = $this->createMock(ProviderManager::class);
        $providerManager->expects($this->never())->method('deletePackage');

        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->expects($this->once())->method('deletePackageMetadata')->with('test/pkg');
        $packageManager->expects($this->once())->method('deletePackageCdnMetadata')->with('test/pkg');
        $packageManager->expects($this->once())->method('deletePackageSearchIndex')->with('test/pkg');

        $worker = new PackagePurgeWorker($this->mockRegistry(null), $providerManager, $packageManager);

        $result = $worker->process(new Job('id', 'package:purge', ['name' => 'test/pkg']), SignalHandler::create());

        self::assertSame(Job::STATUS_COMPLETED, $result['status']);
    }

    private function mockRegistry(?Package $package): ManagerRegistry
    {
        $repo = $this->createStub(ObjectRepository::class);
        $repo->method('findOneBy')->willReturn($package);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getRepository')->willReturn($repo);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));

        return $registry;
    }
}
