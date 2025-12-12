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

namespace App\Tests\Model;

use App\Audit\AuditRecordType;
use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\User;
use App\Model\PackageManager;
use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\TestWith;

class PackageManagerTest extends IntegrationTestCase
{
    private PackageManager $packageManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packageManager = self::getContainer()->get(PackageManager::class);
    }

    public function testNotifyFailure(): void
    {
        $this->markTestSkipped('Do it!');

        $client = self::createClient();

        $package = new Package();
        $package->setRepository($url);

        $user = new User();
        $user->addPackage($package);

        $repo = $this->createMock('App\Entity\UserRepository');
        $em = $this->createMock('Doctrine\ORM\EntityManager');
        $updater = $this->createMock('App\Package\Updater');

        $repo->expects($this->once())
            ->method('findOneBy')
            ->with($this->equalTo(['username' => 'test', 'apiToken' => 'token']))
            ->willReturn($user);

        static::$kernel->getContainer()->set('test.user_repo', $repo);
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', $em);
        static::$kernel->getContainer()->set('App\Package\Updater', $updater);

        $payload = json_encode(['repository' => ['url' => 'git://github.com/composer/composer']]);
        $client->request('POST', '/api/github?username=test&apiToken=token', ['payload' => $payload]);
        $this->assertEquals(202, $client->getResponse()->getStatusCode());
    }

    #[TestWith([false, 0])]
    #[TestWith([true, 1])]
    public function testTransferPackageReplacesAllMaintainers(bool $notifyNewMaintainers, int $expectedEmailCount): void
    {
        $alice = self::createUser('alice', 'alice@example.org');
        $bob = self::createUser('bob', 'bob@example.org');
        $john = self::createUser('john', 'john@example.org');
        $this->store($alice, $bob, $john);

        $package = self::createPackage('vendor/package', 'https://github.com/vendor/package', maintainers: [$john, $alice]);
        $this->store($package);

        $result = $this->packageManager->transferPackage($package, [$bob, $alice], $notifyNewMaintainers);

        $em = self::getEM();
        $em->flush();
        $em->clear();

        $this->assertTrue($result);

        $callable = fn (User $user) => $user->getUsernameCanonical();
        $this->assertEqualsCanonicalizing(['alice', 'bob'], array_map($callable, $package->getMaintainers()->toArray()));
        $this->assertAuditLogWasCreated($package, ['john', 'alice'], ['bob', 'alice']);
        $this->assertEmailCount($expectedEmailCount);
    }

    public function testTransferPackageWithSameMaintainersDoesNothing(): void
    {
        $alice = self::createUser('alice', 'alice@example.org');
        $bob = self::createUser('bob', 'bob@example.org');
        $this->store($alice, $bob);

        $package = self::createPackage('vendor/package', 'https://github.com/vendor/package', maintainers: [$bob, $alice]);
        $this->store($package);

        $result = $this->packageManager->transferPackage($package, [$alice, $bob], false);

        $em = self::getEM();
        $em->flush();
        $em->clear();

        $this->assertFalse($result);

        $record = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::PackageTransferred->value,
            'packageId' => $package->getId(),
        ]);

        $this->assertNull($record, 'No audit record should be created when maintainers are the same');
    }

    /**
     * @param array<string> $oldMaintainers
     * @param array<string> $newMaintainers
     */
    private function assertAuditLogWasCreated(Package $package, array $oldMaintainers, array $newMaintainers): void
    {
        $record = self::getEM()->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::PackageTransferred->value,
            'packageId' => $package->getId(),
            'actorId' => null,
        ]);

        $this->assertNotNull($record, 'Audit record should be created for package transfer');
        $this->assertSame($package->getId(), $record->packageId);

        $callable = fn (array $user) => $user['username'];
        $this->assertEqualsCanonicalizing($oldMaintainers, array_map($callable, $record->attributes['previous_maintainers']));
        $this->assertEqualsCanonicalizing($newMaintainers, array_map($callable, $record->attributes['current_maintainers']));
    }
}
