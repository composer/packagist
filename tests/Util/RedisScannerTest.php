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

namespace App\Tests\Util;

use App\Tests\IntegrationTestCase;
use App\Util\RedisScanner;
use Predis\Client;

class RedisScannerTest extends IntegrationTestCase
{
    private const PREFIX = 'scantest:';

    private Client $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $redis = self::getContainer()->get('snc_redis.default');
        assert($redis instanceof Client);
        $this->redis = $redis;

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    public function testFindsEveryMatchingKeyAcrossCursorPages(): void
    {
        // more keys than the COUNT hint, so the cursor has to page
        $expected = [];
        for ($i = 0; $i < 250; $i++) {
            $key = self::PREFIX.'dl:'.$i.':20260904';
            $expected[] = $key;
            $this->redis->set($key, (string) $i);
        }

        $found = RedisScanner::keys($this->redis, self::PREFIX.'dl:*:*', 10);

        sort($expected);
        sort($found);
        self::assertSame($expected, $found);
    }

    public function testExcludesNonMatchingKeys(): void
    {
        $this->redis->set(self::PREFIX.'dl:1:20260904', '1');
        $this->redis->set(self::PREFIX.'other:1:20260904', '1');

        self::assertSame(
            [self::PREFIX.'dl:1:20260904'],
            RedisScanner::keys($this->redis, self::PREFIX.'dl:*:*')
        );
    }

    public function testReturnsNoDuplicates(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->redis->set(self::PREFIX.'dl:'.$i.':20260904', '1');
        }

        $found = RedisScanner::keys($this->redis, self::PREFIX.'dl:*:*', 5);

        self::assertSame($found, array_values(array_unique($found)));
    }

    public function testReturnsEmptyArrayWhenNothingMatches(): void
    {
        self::assertSame([], RedisScanner::keys($this->redis, self::PREFIX.'nothing:*'));
    }

    private function cleanup(): void
    {
        $keys = RedisScanner::keys($this->redis, self::PREFIX.'*');
        if ($keys) {
            $this->redis->unlink($keys);
        }
    }
}
