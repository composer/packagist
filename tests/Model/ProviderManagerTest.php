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

use App\Model\ProviderManager;
use App\Tests\IntegrationTestCase;
use Predis\Client;

class ProviderManagerTest extends IntegrationTestCase
{
    private ProviderManager $providerManager;
    private Client $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providerManager = self::getService(ProviderManager::class);

        $redis = self::getContainer()->get('snc_redis.default');
        self::assertInstanceOf(Client::class, $redis);
        $this->redis = $redis;
    }

    protected function tearDown(): void
    {
        $this->redis->del(['set:providers', 'set:providers:new', 'set:providers:old']);

        parent::tearDown();
    }

    /**
     * The lookup used to repopulate the set from the DB whenever it was missing, and the query
     * behind that runs long enough that every request arriving meanwhile started its own copy.
     * A miss must stay a miss - the packageLink() macro just renders plain text.
     */
    public function testPackageIsProvidedDoesNotRepopulateTheSet(): void
    {
        $this->redis->del('set:providers');

        self::assertFalse($this->providerManager->packageIsProvided('listvendor/virtual-api'));
        self::assertSame(0, (int) $this->redis->exists('set:providers'));
    }

    public function testRebuildProviderSetRefusesAnEmptyList(): void
    {
        $this->redis->sadd('set:providers', ['listvendor/virtual-api']);

        $this->expectException(\RuntimeException::class);

        try {
            $this->providerManager->rebuildProviderSet([]);
        } finally {
            // a failed query must not have wiped what every lookup reads
            self::assertTrue($this->providerManager->packageIsProvided('listvendor/virtual-api'));
        }
    }
}
