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

use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\User;
use App\Log\AuditLogEventType;
use App\Model\MaintainerChangeException;
use App\Model\PackageManager;
use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\TestWith;
use Predis\Client;

class PackageManagerTest extends IntegrationTestCase
{
    private PackageManager $packageManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packageManager = self::getContainer()->get(PackageManager::class);
    }

    public function testRecordFailedCrawlStampsCrawledAtUnlessItIsParkedInTheFuture(): void
    {
        // Without the stamp packagist:update re-selects a failing package on every tick, so a repo
        // that 403s loops through the crawl workers every ten minutes for as long as it is broken.
        $failing = self::createPackage('acme/failing', 'https://github.com/acme/failing');
        $failing->setCrawledAt(new \DateTimeImmutable('-3 days'));
        $parked = self::createPackage('acme/parked', 'https://github.com/acme/parked');
        $parked->setCrawledAt(new \DateTimeImmutable('+7 days'));
        $this->store($failing, $parked);

        $this->packageManager->recordFailedCrawl($failing);
        $this->packageManager->recordFailedCrawl($parked);

        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $failing->getCrawledAt());
        self::assertGreaterThan(new \DateTimeImmutable('+6 days'), $parked->getCrawledAt(), 'an unreachable host parked for a week must not be pulled back to now');
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

        $result = $this->packageManager->transferPackage(null, $package, [$bob, $alice], $notifyNewMaintainers);

        $em = self::getEM();
        $em->flush();
        $em->clear();

        $this->assertTrue($result);

        $callable = static fn (User $user) => $user->getUsernameCanonical();
        $this->assertEqualsCanonicalizing(['alice', 'bob'], array_map($callable, $package->getMaintainers()->toArray()));
        $this->assertAuditLogWasCreated($package, ['john', 'alice'], ['bob', 'alice']);
        $this->assertEmailCount($expectedEmailCount);
    }

    public function testAddMaintainerRefusesInactiveAccountsAndTheMaintainerLimit(): void
    {
        $maintainers = [];
        for ($i = 0; $i < Package::MAX_MAINTAINERS - 1; ++$i) {
            $maintainers[] = self::createUser('maintainer'.$i, 'maintainer'.$i.'@example.org');
        }
        $disabled = self::createUser('disabled', 'disabled@example.org', enabled: false);
        $first = self::createUser('first', 'first@example.org');
        $second = self::createUser('second', 'second@example.org');
        $package = self::createPackage('vendor/package', 'https://github.com/vendor/package', maintainers: $maintainers);
        $this->store($disabled, $first, $second, $package, ...$maintainers);

        try {
            $this->packageManager->addMaintainer($package, $disabled, null);
            self::fail('Adding a disabled account must be refused');
        } catch (MaintainerChangeException) {
        }

        self::assertTrue($this->packageManager->addMaintainer($package, $first, null));

        $this->expectException(MaintainerChangeException::class);
        $this->expectExceptionMessage('A package can have at most 10 maintainers');
        $this->packageManager->addMaintainer($package, $second, null);
    }

    public function testRemoveMaintainerKeepsTheLastMaintainer(): void
    {
        $alice = self::createUser('alice', 'alice@example.org');
        $bob = self::createUser('bob', 'bob@example.org');
        $package = self::createPackage('vendor/package', 'https://github.com/vendor/package', maintainers: [$alice, $bob]);
        $this->store($alice, $bob, $package);

        self::assertTrue($this->packageManager->removeMaintainer($package, $bob, null));
        self::assertFalse($this->packageManager->removeMaintainer($package, $bob, null));

        $this->expectException(MaintainerChangeException::class);
        $this->expectExceptionMessage('A package must keep at least one maintainer');
        $this->packageManager->removeMaintainer($package, $alice, null);
    }

    public function testTransferPackageWithSameMaintainersDoesNothing(): void
    {
        $alice = self::createUser('alice', 'alice@example.org');
        $bob = self::createUser('bob', 'bob@example.org');
        $this->store($alice, $bob);

        $package = self::createPackage('vendor/package', 'https://github.com/vendor/package', maintainers: [$bob, $alice]);
        $this->store($package);

        $result = $this->packageManager->transferPackage(null, $package, [$alice, $bob], false);

        $em = self::getEM();
        $em->flush();
        $em->clear();

        $this->assertFalse($result);

        $record = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditLogEventType::PackageTransferred->value,
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
            'type' => AuditLogEventType::PackageTransferred->value,
            'packageId' => $package->getId(),
            'actorId' => null,
        ]);

        $this->assertNotNull($record, 'Audit record should be created for package transfer');
        $this->assertSame($package->getId(), $record->packageId);

        $callable = static fn (array $user) => $user['username'];
        $this->assertEqualsCanonicalizing($oldMaintainers, array_map($callable, $record->attributes['previous_maintainers']));
        $this->assertEqualsCanonicalizing($newMaintainers, array_map($callable, $record->attributes['current_maintainers']));
    }

    #[TestWith([PackageFreezeReason::Spam, true])]
    #[TestWith([PackageFreezeReason::Temporary, false])]
    public function testFreezeDropsTheViewCounterOnlyWhenThePageStopsBeingServed(PackageFreezeReason $reason, bool $expectDropped): void
    {
        $package = self::createPackage('test/frozen', 'https://example.org/test/frozen');
        $this->store($package);

        $redis = self::getContainer()->get('snc_redis.default');
        self::assertInstanceOf(Client::class, $redis);
        $key = 'views:'.$package->getId();
        $redis->set($key, '42');

        $this->packageManager->freeze($package, $reason);

        if ($expectDropped) {
            self::assertNull($redis->get($key), 'a suppressed package 404s, so nothing can read the counter anymore');
        } else {
            self::assertSame('42', $redis->get($key), 'a gentle freeze keeps serving the page, so the counter stays live');
        }

        $redis->del([$key]);
    }
}
