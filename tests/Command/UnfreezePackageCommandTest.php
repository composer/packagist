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

use App\Command\UnfreezePackageCommand;
use App\Entity\Job;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Model\PackageManager;
use App\Tests\IntegrationTestCase;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Tester\CommandTester;

class UnfreezePackageCommandTest extends IntegrationTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();

        $command = new UnfreezePackageCommand(
            self::getContainer()->get(ManagerRegistry::class),
            self::getContainer()->get(PackageManager::class),
        );
        $this->commandTester = new CommandTester($command);
    }

    public function testUnfreezeClearsFrozenFlagAndSchedulesForcedUpdate(): void
    {
        // Shares PackageManager::unfreeze() with the web action, so this also covers that path.
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $package->freeze(PackageFreezeReason::Spam);
        $this->store($package);
        $packageId = $package->getId();

        $this->commandTester->execute(['package' => 'test/pkg']);
        $this->commandTester->assertCommandIsSuccessful();

        $em = self::getEM();
        $em->clear();

        $package = $em->find(Package::class, $packageId);
        self::assertNotNull($package);
        self::assertFalse($package->isFrozen());

        // A suppressing freeze purged the published metadata, so unfreeze is the one path that nulls
        // dumpedAtV2 outright — plus marks, so a dumper run already in flight cannot erase the null
        // when it records its dump times at the end.
        self::assertNull($package->getDumpedAtV2(), 'unfreeze should treat the package as never dumped');
        self::assertNotNull($package->getDumpRequestedAt(), 'unfreeze must also mark, or an in-flight dump run overwrites the null');
        self::assertTrue($package->isDumpRequested());

        $job = $em->getRepository(Job::class)->findOneBy(['type' => 'package:updates', 'packageId' => $packageId]);
        self::assertNotNull($job, 'unfreeze should schedule a package update');
        self::assertTrue($job->getPayload()['force_dump'] ?? false, 'the scheduled update should force a re-dump');
    }
}
