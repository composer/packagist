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

use App\Command\FreezePackageCommand;
use App\Entity\AuditRecord;
use App\Entity\Job;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Log\AuditLogEventType;
use App\Model\PackageManager;
use App\Tests\IntegrationTestCase;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class FreezePackageCommandTest extends IntegrationTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();

        $command = new FreezePackageCommand(
            self::getContainer()->get(ManagerRegistry::class),
            self::getContainer()->get(PackageManager::class),
        );
        $this->commandTester = new CommandTester($command);
    }

    public function testFreezeRecordsAdminActorAndSchedulesPurge(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $this->store($package);
        $packageId = $package->getId();

        $this->commandTester->execute(['package' => 'test/pkg', 'reason' => 'spam']);
        $this->commandTester->assertCommandIsSuccessful();

        $em = self::getEM();
        $em->clear();

        $package = $em->find(Package::class, $packageId);
        self::assertNotNull($package);
        self::assertSame(PackageFreezeReason::Spam, $package->getFreezeReason());

        $audit = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditLogEventType::PackageFrozen, 'packageId' => $packageId]);
        self::assertNotNull($audit);
        self::assertSame('admin', $audit->attributes['actor']);
        self::assertSame('spam', $audit->attributes['reason']);

        self::assertNotNull($em->getRepository(Job::class)->findOneBy(['type' => 'package:purge', 'packageId' => $packageId]));
    }

    public function testGentleFreezeDoesNotSchedulePurge(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $this->store($package);
        $packageId = $package->getId();

        $this->commandTester->execute(['package' => 'test/pkg', 'reason' => 'temporary']);
        $this->commandTester->assertCommandIsSuccessful();

        $em = self::getEM();
        $em->clear();

        self::assertSame(PackageFreezeReason::Temporary, $em->find(Package::class, $packageId)?->getFreezeReason());
        self::assertNull($em->getRepository(Job::class)->findOneBy(['type' => 'package:purge', 'packageId' => $packageId]));
    }

    public function testRejectsReasonNotAvailableToAdmins(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $this->store($package);
        $packageId = $package->getId();

        foreach (['remote_id', 'bogus'] as $reason) {
            self::assertSame(Command::FAILURE, $this->commandTester->execute(['package' => 'test/pkg', 'reason' => $reason]));
        }

        self::getEM()->clear();
        self::assertFalse(self::getEM()->find(Package::class, $packageId)?->isFrozen());
    }

    public function testFailsForUnknownPackage(): void
    {
        self::assertSame(Command::FAILURE, $this->commandTester->execute(['package' => 'test/missing', 'reason' => 'spam']));
        self::assertStringContainsString('not found', $this->commandTester->getDisplay());
    }

    public function testDoesNotOverwriteExistingFreeze(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $package->freeze(PackageFreezeReason::Gone);
        $this->store($package);
        $packageId = $package->getId();

        self::assertSame(Command::FAILURE, $this->commandTester->execute(['package' => 'test/pkg', 'reason' => 'spam']));

        self::getEM()->clear();
        self::assertSame(PackageFreezeReason::Gone, self::getEM()->find(Package::class, $packageId)?->getFreezeReason());
    }
}
