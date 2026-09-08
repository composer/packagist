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

namespace App\Tests\Package;

use App\Entity\PackageFreezeReason;
use App\Model\PackageManager;
use App\Model\ProviderManager;
use App\Package\PackageListCache;
use App\Tests\IntegrationTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

class PackageListCacheTest extends IntegrationTestCase
{
    private PackageListCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = self::getService(PackageListCache::class);
        $this->cache->clear();
    }

    protected function tearDown(): void
    {
        $this->cache->clear();

        parent::tearDown();
    }

    public function testReadReturnsNullUntilWritten(): void
    {
        self::assertNull($this->cache->read());
    }

    public function testBlobDecodesToTheSameBytesTheStreamedEndpointWouldEmit(): void
    {
        $names = ['listvendor/aaa', 'listvendor/abb', 'listvendor/bbb'];

        $this->cache->write($names, 7);

        $blob = $this->cache->read();
        self::assertNotNull($blob);
        // must stay byte-identical to the streamed responses it replaces, escaped slashes included
        self::assertSame(
            json_encode(['packageNames' => $names], JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES),
            gzdecode($blob),
        );
        self::assertSame(7, $this->cache->getBuiltVersion());
    }

    public function testMarkStaleLeavesTheVersionAheadOfTheBuiltOne(): void
    {
        self::assertSame(0, $this->cache->getVersion());

        $this->cache->write(['listvendor/aaa'], $this->cache->getVersion());
        self::assertSame($this->cache->getVersion(), $this->cache->getBuiltVersion());

        $this->cache->markStale();

        self::assertSame(1, $this->cache->getVersion());
        self::assertNotSame($this->cache->getVersion(), $this->cache->getBuiltVersion(), 'a change must leave the blob stale');
    }

    public function testAChangeLandingMidBuildStaysStale(): void
    {
        // the writer stores the version it read *before* querying, so a bump that lands while the
        // build is in flight is still pending afterwards rather than being swallowed
        $versionAtBuildStart = $this->cache->getVersion();
        $this->cache->markStale();

        $this->cache->write(['listvendor/aaa'], $versionAtBuildStart);

        self::assertNotSame($this->cache->getVersion(), $this->cache->getBuiltVersion());
    }

    public function testSuppressingFreezeMarksTheListStale(): void
    {
        $package = self::createPackage('listvendor/spam', 'https://example.org/spam');
        $this->store($package);
        $this->cache->write(['listvendor/spam'], $this->cache->getVersion());
        self::assertSame($this->cache->getVersion(), $this->cache->getBuiltVersion());

        // suppressed packages drop out of the DB-derived listing, so the blob must not keep serving
        // them until the purge worker gets around to it
        self::getService(PackageManager::class)->freeze($package, PackageFreezeReason::Spam);

        self::assertNotSame($this->cache->getVersion(), $this->cache->getBuiltVersion());
    }

    public function testInsertAndDeleteMarkTheListStale(): void
    {
        $providerManager = self::getService(ProviderManager::class);
        $package = self::createPackage('listvendor/aaa', 'https://example.org/aaa');
        $this->store($package);

        $this->cache->write(['listvendor/aaa'], $this->cache->getVersion());
        $providerManager->insertPackage($package);
        self::assertNotSame($this->cache->getVersion(), $this->cache->getBuiltVersion(), 'insert must invalidate');

        $this->cache->write(['listvendor/aaa'], $this->cache->getVersion());
        $providerManager->deletePackage($package);
        self::assertNotSame($this->cache->getVersion(), $this->cache->getBuiltVersion(), 'delete must invalidate');
    }

    public function testClearRemovesTheBlob(): void
    {
        $this->cache->write(['listvendor/aaa'], 1);
        self::assertNotNull($this->cache->read());

        $this->cache->clear();

        self::assertNull($this->cache->read());
        self::assertSame(0, $this->cache->getVersion());
        self::assertSame(0, $this->cache->getBuiltVersion());
    }
}
