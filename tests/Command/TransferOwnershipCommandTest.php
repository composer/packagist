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

use App\Audit\AuditRecordType;
use App\Command\TransferOwnershipCommand;
use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\User;
use App\Tests\IntegrationTestCase;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TransferOwnershipCommandTest extends IntegrationTestCase
{
    private CommandTester $commandTester;

    private Package $package1;
    private Package $package2;
    private Package $package3;

    protected function setUp(): void
    {
        parent::setUp();

        $alice = self::createUser('alice', 'alice@example.org');
        $bob = self::createUser('bob', 'bob@example.org');
        $john = self::createUser('john', 'john@example.org');
        $this->store($alice, $bob, $john);

        $this->package1 = self::createPackage('vendor1/package1', 'https://github.com/vendor1/package1', maintainers: [$john, $alice]);
        $this->package2 = self::createPackage('vendor1/package2', 'https://github.com/vendor1/package2',maintainers: [$john, $bob]);
        $this->package3 = self::createPackage('vendor2/package1', 'https://github.com/vendor2/package1',maintainers: [$john]);
        $this->store($this->package1, $this->package2, $this->package3);

        $command = new TransferOwnershipCommand(self::getContainer()->get(ManagerRegistry::class));
        $this->commandTester = new CommandTester($command);
    }

    public function testExecuteSuccessForVendor(): void
    {
        $this->commandTester->execute([
            'vendorOrPackage' => 'vendor1',
            'maintainers' => ['bob', 'alice'],
        ]);

        $this->commandTester->assertCommandIsSuccessful();

        $em = self::getEM();
        $em->clear();

        $package1 = $em->find(Package::class, $this->package1->getId());
        $package2 = $em->find(Package::class, $this->package2->getId());
        $package3 = $em->find(Package::class, $this->package3->getId());

        $this->assertNotNull($package1);
        $this->assertNotNull($package2);
        $this->assertNotNull($package3);

        $callable = fn (User $user) => $user->getUsernameCanonical();
        $this->assertEqualsCanonicalizing(['alice', 'bob'], array_map($callable, $package1->getMaintainers()->toArray()));
        $this->assertEqualsCanonicalizing(['alice', 'bob'], array_map($callable, $package2->getMaintainers()->toArray()));
        $this->assertEqualsCanonicalizing(['john'], array_map($callable, $package3->getMaintainers()->toArray()), 'vendor2 packages should not be changed');

        $this->assertAuditLogWasCreated($package1, ['john', 'alice'], ['alice', 'bob']);
        $this->assertAuditLogWasCreated($package2, ['john', 'bob'], ['alice', 'bob']);
    }

    public function testExecuteSuccessForPackage(): void
    {
        $this->commandTester->execute([
            'vendorOrPackage' => 'vendor2/package1',
            'maintainers' => ['john', 'alice'],
        ]);

        $this->commandTester->assertCommandIsSuccessful();

        $em = self::getEM();
        $em->clear();

        $package2 = $em->find(Package::class, $this->package2->getId());
        $package3 = $em->find(Package::class, $this->package3->getId());

        $this->assertNotNull($package2);
        $this->assertNotNull($package3);

        $callable = fn (User $user) => $user->getUsernameCanonical();
        $this->assertEqualsCanonicalizing(['bob', 'john'], array_map($callable, $package2->getMaintainers()->toArray()), 'vendor1 packages should not be changed');
        $this->assertEqualsCanonicalizing(['alice', 'john'], array_map($callable, $package3->getMaintainers()->toArray()));

        $this->assertAuditLogWasCreated($package3, ['john'], ['alice', 'john']);
    }

    public function testExecuteWithDryRunDoesNothing(): void
    {
        $this->commandTester->execute([
            'vendorOrPackage' => 'vendor1',
            'maintainers' => ['alice'],
            '--dry-run' => true,
        ]);

        $this->commandTester->assertCommandIsSuccessful();

        $em = self::getEM();
        $em->clear();

        $package1 = $em->find(Package::class, $this->package1->getId());
        $package2 = $em->find(Package::class, $this->package2->getId());

        $this->assertNotNull($package1);
        $this->assertNotNull($package2);

        $callable = fn (User $user) => $user->getUsernameCanonical();
        $this->assertEqualsCanonicalizing(['john', 'alice'], array_map($callable, $package1->getMaintainers()->toArray()));
    }

    public function testExecuteIgnoresIdenticalMaintainers(): void
    {
        $this->commandTester->execute([
            'vendorOrPackage' => 'vendor1',
            'maintainers' => ['alice', 'john'],
        ]);

        $this->commandTester->assertCommandIsSuccessful();

        $em = self::getEM();
        $em->clear();

        $package1 = $em->find(Package::class, $this->package1->getId());
        $package2 = $em->find(Package::class, $this->package2->getId());

        $this->assertNotNull($package1);
        $this->assertNotNull($package2);

        $callable = fn (User $user) => $user->getUsernameCanonical();
        $this->assertEqualsCanonicalizing(['alice', 'john'], array_map($callable, $package1->getMaintainers()->toArray()));
        $this->assertEqualsCanonicalizing(['alice', 'john'], array_map($callable, $package2->getMaintainers()->toArray()));

        $record = $this->retrieveAuditRecordForPackage($package1);
        $this->assertNull($record, 'No audit log should be created if package maintainers are identical');
        $this->assertAuditLogWasCreated($package2, ['john', 'bob'], ['alice', 'john']);
    }

    public function testExecuteFailsWithUnknownMaintainers(): void
    {
        $this->commandTester->execute([
            'vendorOrPackage' => 'vendor1',
            'maintainers' => ['unknown1', 'alice', 'unknown2'],
        ]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('2 maintainers could not be found', $output);
    }

    public function testExecuteFailsIfNoVendorPackagesFound(): void
    {
        $this->commandTester->execute([
            'vendorOrPackage' => 'foobar',
            'maintainers' => ['bob', 'alice'],
        ]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No packages found for foobar', $output);
    }

    /**
     * @param string[] $oldMaintainers
     * @param string[] $newMaintainers
     */
    private function assertAuditLogWasCreated(Package $package, array $oldMaintainers, array $newMaintainers): void
    {
        $record = $this->retrieveAuditRecordForPackage($package);
        $this->assertNotNull($record);
        $this->assertSame('admin', $record->attributes['actor']);
        $this->assertSame($package->getId(), $record->packageId);

        $callable = fn (array $user) => $user['username'];
        $this->assertEqualsCanonicalizing($oldMaintainers, array_map($callable, $record->attributes['previous_maintainers']));
        $this->assertEqualsCanonicalizing($newMaintainers, array_map($callable, $record->attributes['current_maintainers']));
    }

    private function retrieveAuditRecordForPackage(Package $package): ?AuditRecord
    {
        return $this->getEM()->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::PackageTransferred->value,
            'packageId' => $package->getId(),
            'actorId' => null,
        ]);
    }
}
