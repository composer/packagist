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

use App\Model\DownloadManager;
use App\Tests\IntegrationTestCase;
use Predis\Client;

/**
 * Exercises the downloadsIncr lua script against a real Redis, since its KEYS/ARGV
 * index arithmetic cannot be verified any other way.
 */
class DownloadManagerTest extends IntegrationTestCase
{
    // TEST-NET-3, never a real client address
    private const IP = '203.0.113.7';
    private const PKG_A = 999001;
    private const PKG_B = 999002;

    private DownloadManager $downloadManager;
    private Client $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->downloadManager = self::getService(DownloadManager::class);
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

    public function testThrottleKeyIsAHashKeyedByIp(): void
    {
        $this->addDownloads([self::PKG_A, self::PKG_B]);

        $key = $this->throttleKey();
        self::assertSame('hash', (string) $this->redis->type($key));
        self::assertSame(
            [(string) self::PKG_A => '1', (string) self::PKG_B => '1'],
            $this->redis->hgetall($key)
        );
    }

    public function testStatsKeysAreIncrementedPerJob(): void
    {
        $this->addDownloads([self::PKG_A]);
        $this->addDownloads([self::PKG_A]);

        self::assertSame(2, (int) $this->redis->get('dl:'.self::PKG_A));
        self::assertSame(2, (int) $this->redis->get('dl:'.self::PKG_A.':'.date('Ymd')));
    }

    public function testDownloadsStopBeingCountedAfterTenPerPackage(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->addDownloads([self::PKG_A]);
        }

        // the throttle counter keeps climbing, but only the first 10 are counted as downloads
        self::assertSame(12, (int) $this->redis->hget($this->throttleKey(), (string) self::PKG_A));
        self::assertSame(10, (int) $this->redis->get('dl:'.self::PKG_A));
    }

    public function testThrottlingIsPerPackageNotPerIp(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->addDownloads([self::PKG_A]);
        }
        $this->addDownloads([self::PKG_B]);

        // PKG_A being throttled must not stop PKG_B from counting on the same IP
        self::assertSame(1, (int) $this->redis->get('dl:'.self::PKG_B));
    }

    public function testExpiryLandsInTheJitterWindow(): void
    {
        $this->addDownloads([self::PKG_A]);

        $boundary = $this->throttleBoundary();
        $expiry = $this->absoluteExpiry($this->throttleKey());

        self::assertGreaterThanOrEqual($boundary, $expiry);
        self::assertLessThanOrEqual($boundary + 3600, $expiry);
    }

    public function testExpiryIsSpreadAcrossKeysRatherThanShared(): void
    {
        // The regression guard: every key used to share one absolute PEXPIREAT, so the whole
        // day's throttle keys were freed at once and stalled Redis' main thread.
        $expiries = [];
        for ($i = 0; $i < 40; $i++) {
            $ip = '203.0.113.'.$i;
            $this->downloadManager->addDownloads(
                [['id' => self::PKG_A, 'vid' => 1, 'minor' => '8.3']],
                $ip,
                '8.3',
                '8.3'
            );
            $expiries[] = $this->absoluteExpiry('throttle:'.$ip.':'.date('Ymd', $this->throttleBoundary()));
            $this->redis->del(['throttle:'.$ip.':'.date('Ymd', $this->throttleBoundary())]);
        }

        self::assertGreaterThan(10, count(array_unique($expiries)), 'expiries should be spread, not shared');
    }

    public function testExpiryIsNotPushedOutByLaterRequests(): void
    {
        $this->addDownloads([self::PKG_A]);
        $key = $this->throttleKey();

        // move it far out, then confirm a second request leaves it alone
        $this->redis->pexpireat($key, (time() + 86400 * 10) * 1000);
        $this->addDownloads([self::PKG_A]);

        self::assertGreaterThan(86400 * 9, (int) $this->redis->ttl($key));
    }

    /**
     * @param list<int> $packageIds
     */
    private function addDownloads(array $packageIds): void
    {
        $jobs = [];
        foreach ($packageIds as $id) {
            $jobs[] = ['id' => $id, 'vid' => $id + 500000, 'minor' => '8.3'];
        }

        $this->downloadManager->addDownloads($jobs, self::IP, '8.3', '8.3');
    }

    private function throttleBoundary(): int
    {
        return (int) strtotime('tomorrow 12:00:00', time() - 86400 / 2);
    }

    private function throttleKey(): string
    {
        return 'throttle:'.self::IP.':'.date('Ymd', $this->throttleBoundary());
    }

    /** Absolute expiry in seconds. PEXPIRETIME needs Redis 7, PTTL works everywhere. */
    private function absoluteExpiry(string $key): int
    {
        $pttl = (int) $this->redis->pttl($key);
        self::assertGreaterThan(0, $pttl, 'key '.$key.' has no TTL');

        return (int) round((microtime(true) * 1000 + $pttl) / 1000);
    }

    private function cleanup(): void
    {
        $day = date('Ymd');
        $keys = [$this->throttleKey()];
        foreach ([self::PKG_A, self::PKG_B] as $id) {
            $keys[] = 'dl:'.$id;
            $keys[] = 'dl:'.$id.':'.$day;
            $keys[] = 'dl:'.$id.'-'.($id + 500000).':'.$day;
            $keys[] = 'phpplatform:'.$id.'-8.3:8.3:'.$day;
        }
        for ($i = 0; $i < 40; $i++) {
            $keys[] = 'throttle:203.0.113.'.$i.':'.date('Ymd', $this->throttleBoundary());
        }

        $this->redis->del($keys);
    }
}
