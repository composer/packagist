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

use App\Command\DumpPackageListCommand;
use App\Entity\PackageFreezeReason;
use App\Entity\PackageRepository;
use App\Model\ProviderManager;
use App\Package\PackageListCache;
use App\Service\Locker;
use App\Tests\IntegrationTestCase;
use Predis\Client;
use Symfony\Component\Console\Tester\CommandTester;

class DumpPackageListCommandTest extends IntegrationTestCase
{
    private CommandTester $commandTester;
    private PackageListCache $cache;
    private Client $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = self::getService(PackageListCache::class);
        $this->cache->clear();

        $redis = self::getContainer()->get('snc_redis.default');
        self::assertInstanceOf(Client::class, $redis);
        $this->redis = $redis;

        $this->commandTester = new CommandTester(new DumpPackageListCommand(
            $this->cache,
            self::getService(ProviderManager::class),
            self::getService(PackageRepository::class),
            self::getService(Locker::class),
        ));
    }

    protected function tearDown(): void
    {
        $this->cache->clear();
        $this->redis->del(['set:packages:new', 'set:packages:old']);

        parent::tearDown();
    }

    /**
     * @return string[]
     */
    private function dumpedNames(): array
    {
        $blob = $this->cache->read();
        self::assertNotNull($blob);
        $decoded = json_decode((string) gzdecode($blob), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded['packageNames'];
    }

    public function testDumpsSortedNamesAndSkipsSuppressedPackages(): void
    {
        $bbb = self::createPackage('listvendor/bbb', 'https://example.org/bbb');
        $aaa = self::createPackage('listvendor/aaa', 'https://example.org/aaa');
        $spam = self::createPackage('listvendor/spam', 'https://example.org/spam');
        $spam->freeze(PackageFreezeReason::Spam);
        $this->store($bbb, $aaa, $spam);

        $this->commandTester->execute([]);
        $this->commandTester->assertCommandIsSuccessful();

        $names = $this->dumpedNames();
        self::assertContains('listvendor/aaa', $names);
        self::assertContains('listvendor/bbb', $names);
        self::assertNotContains('listvendor/spam', $names, 'suppressed packages must stay out of the listing');
        self::assertSame(array_values($names), $names, 'names must be a list, not a map');

        $sorted = $names;
        sort($sorted, \SORT_STRING | \SORT_FLAG_CASE);
        self::assertSame($sorted, $names, 'output order is part of the endpoint contract');
    }

    public function testBuildsOnAColdCacheWhereBothCountersAreZero(): void
    {
        $this->store(self::createPackage('listvendor/aaa', 'https://example.org/aaa'));

        // nothing has been dumped and nothing has been marked stale, so the version comparison
        // alone says "up to date" - the missing blob still has to be built
        self::assertSame(0, $this->cache->getVersion());
        self::assertSame(0, $this->cache->getBuiltVersion());

        $this->commandTester->execute([]);
        $this->commandTester->assertCommandIsSuccessful();

        self::assertContains('listvendor/aaa', $this->dumpedNames());
    }

    public function testSkipsRebuildWhenNothingChanged(): void
    {
        $this->store(self::createPackage('listvendor/aaa', 'https://example.org/aaa'));

        $this->commandTester->execute([]);
        $firstBlob = $this->cache->read();
        self::assertNotNull($firstBlob);

        // a second package is added straight to the DB, without the INCR insertPackage() would do,
        // so the version has not moved and the dump must be left alone
        $this->store(self::createPackage('listvendor/bbb', 'https://example.org/bbb'));

        $this->commandTester->execute([]);

        self::assertSame($firstBlob, $this->cache->read());
        self::assertNotContains('listvendor/bbb', $this->dumpedNames());
    }

    public function testRebuildsOnceStaleAndWithForce(): void
    {
        $this->store(self::createPackage('listvendor/aaa', 'https://example.org/aaa'));
        $this->commandTester->execute([]);

        $this->store(self::createPackage('listvendor/bbb', 'https://example.org/bbb'));

        $this->cache->markStale();
        $this->commandTester->execute([]);
        self::assertContains('listvendor/bbb', $this->dumpedNames());
        self::assertSame($this->cache->getVersion(), $this->cache->getBuiltVersion());

        // --force rebuilds even though the version has not moved
        $this->store(self::createPackage('listvendor/ccc', 'https://example.org/ccc'));
        $this->commandTester->execute(['--force' => true]);
        self::assertContains('listvendor/ccc', $this->dumpedNames());
    }

    public function testRebuildSetMatchesTheDbAndLeavesNoScratchKeys(): void
    {
        $providerManager = self::getService(ProviderManager::class);
        $this->store(self::createPackage('listvendor/aaa', 'https://example.org/aaa'));

        // a name that is in the set but no longer in the DB - exactly the drift this resets
        $this->redis->sadd('set:packages', ['listvendor/gone']);
        self::assertTrue($providerManager->packageExists('listvendor/gone'));

        $this->commandTester->execute(['--rebuild-set' => true]);
        $this->commandTester->assertCommandIsSuccessful();

        self::assertTrue($providerManager->packageExists('listvendor/aaa'));
        self::assertFalse($providerManager->packageExists('listvendor/gone'), 'drift must be reset');
        self::assertSame(0, (int) $this->redis->exists('set:packages:new'));
        self::assertSame(0, (int) $this->redis->exists('set:packages:old'));
    }
}
