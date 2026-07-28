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

namespace App\Tests\Command;

use App\Audit\VersionDeletionReason;
use App\Command\UnfreezePackageCommand;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\Version;
use App\Model\ProviderManager;
use App\Service\Scheduler;
use App\Tests\IntegrationTestCase;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Console\Tester\CommandTester;

class UnfreezePackageCommandTest extends IntegrationTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();

        $command = new UnfreezePackageCommand(
            self::getContainer()->get(ProviderManager::class),
            self::getContainer()->get(ManagerRegistry::class),
            self::getContainer()->get(Scheduler::class),
        );
        $this->commandTester = new CommandTester($command);
    }

    #[TestWith([PackageFreezeReason::Spam])]
    #[TestWith([PackageFreezeReason::Malware])]
    public function testUnfreezeRecoversHiddenVersionsForSuppressingReasons(PackageFreezeReason $reason): void
    {
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $package->freeze($reason);

        $version = new Version();
        $version->setPackage($package);
        $version->setName($package->getName());
        $version->setVersion('1.0.0');
        $version->setNormalizedVersion('1.0.0.0');
        $version->setDevelopment(false);
        $version->setLicense([]);
        $version->setAutoload([]);
        // Simulate the purge worker having hidden the version.
        $version->setSoftDeletedAt(new \DateTimeImmutable());
        $version->setDeletionReason(VersionDeletionReason::Hidden);
        $package->getVersions()->add($version);

        $this->store($package, $version);
        $versionId = $version->getId();

        $this->commandTester->execute(['package' => 'test/pkg']);
        $this->commandTester->assertCommandIsSuccessful();

        $em = self::getEM();
        $em->clear();

        $package = $em->getRepository(Package::class)->findOneBy(['name' => 'test/pkg']);
        self::assertNotNull($package);
        self::assertFalse($package->isFrozen());

        $version = $em->find(Version::class, $versionId);
        self::assertNotNull($version);
        self::assertFalse($version->isSoftDeleted(), 'hidden versions must be recovered on unfreeze regardless of spam vs malware');
        self::assertNull($version->getDeletionReason());
    }
}
