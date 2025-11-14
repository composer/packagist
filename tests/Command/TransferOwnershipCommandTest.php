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

use App\Command\TransferOwnershipCommand;
use App\Entity\Package;
use App\Entity\User;
use App\Model\PackageManager;
use App\Tests\IntegrationTestCase;
use Doctrine\Persistence\ManagerRegistry;
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

        $command = new TransferOwnershipCommand(self::getContainer()->get(ManagerRegistry::class), self::getContainer()->get(PackageManager::class));
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
}
