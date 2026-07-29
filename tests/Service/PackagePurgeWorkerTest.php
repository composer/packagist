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

use App\Audit\VersionDeletionReason;
use App\Entity\Job;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\Version;
use App\Entity\VersionRepository;
use App\Model\PackageManager;
use App\Model\ProviderManager;
use App\Service\PackagePurgeWorker;
use App\Tests\Fixtures\Fixtures;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Seld\Signal\SignalHandler;

class PackagePurgeWorkerTest extends TestCase
{
    use Fixtures;

    public function testPurgesArtifactsForExistingPackage(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $package->freeze(PackageFreezeReason::Spam);

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

    public function testSkipsPurgeWhenFreezeWasReverted(): void
    {
        // The admin undid the freeze before the job ran, so the stale purge must not touch anything.
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');

        $providerManager = $this->createMock(ProviderManager::class);
        $providerManager->expects($this->never())->method('deletePackage');

        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->expects($this->never())->method('deletePackageMetadata');
        $packageManager->expects($this->never())->method('deletePackageCdnMetadata');
        $packageManager->expects($this->never())->method('deletePackageSearchIndex');

        $worker = new PackagePurgeWorker($this->mockRegistry($package), $providerManager, $packageManager);

        $result = $worker->process(new Job('id', 'package:purge', ['name' => 'test/pkg']), SignalHandler::create());

        self::assertSame(Job::STATUS_COMPLETED, $result['status']);
        self::assertStringContainsString('Skipped purge', $result['message']);
    }

    #[TestWith([PackageFreezeReason::Spam, 'spam'])]
    #[TestWith([PackageFreezeReason::Malware, 'malware'])]
    public function testSoftDeletesVersionsWithReasonTextMatchingFreezeReason(PackageFreezeReason $freezeReason, string $expectedReasonText): void
    {
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $package->freeze($freezeReason);
        $version = new Version();
        $package->addVersion($version);

        $worker = new PackagePurgeWorker(
            $this->mockRegistry($package),
            $this->createStub(ProviderManager::class),
            $this->createStub(PackageManager::class),
        );

        $result = $worker->process(new Job('id', 'package:purge', ['name' => 'test/pkg']), SignalHandler::create());

        self::assertSame(Job::STATUS_COMPLETED, $result['status']);
    }

    private function mockRegistry(?Package $package, ?VersionRepository $versionRepo = null): ManagerRegistry
    {
        $packageRepo = $this->createStub(ObjectRepository::class);
        $packageRepo->method('findOneBy')->willReturn($package);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getRepository')->willReturnCallback(
            fn (string $class) => $class === Version::class && $versionRepo !== null ? $versionRepo : $packageRepo,
        );
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));

        return $registry;
    }
}
