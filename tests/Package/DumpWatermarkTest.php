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

use App\Package\DumpWatermark;
use App\Tests\IntegrationTestCase;
use Predis\Client;

class DumpWatermarkTest extends IntegrationTestCase
{
    /** Kept away from the real 1..n layouts so a test run cannot disturb a local dumper. */
    private const NUM_WORKERS = 97;

    private DumpWatermark $watermark;

    private Client $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $redis = self::getContainer()->get('snc_redis.default');
        self::assertInstanceOf(Client::class, $redis);
        $this->redis = $redis;
        $this->watermark = new DumpWatermark($this->redis);

        $this->forgetTestKeys();
    }

    protected function tearDown(): void
    {
        $this->forgetTestKeys();

        parent::tearDown();
    }

    public function testAnUnknownWorkerHasNoWatermark(): void
    {
        // Null has to mean "sweep everything". Read as "nothing changed" it would silently stop
        // dumping, so this is the case the whole design leans on.
        self::assertNull($this->watermark->since(0, self::NUM_WORKERS));
    }

    public function testACompletedRunIsRememberedWithTheSafetyMargin(): void
    {
        $selectedAt = new \DateTimeImmutable('2026-09-24 10:00:00');

        $this->watermark->markCompleted(0, self::NUM_WORKERS, $selectedAt);

        // five seconds back, because dumpRequestedAt and crawledAt hold whole seconds and a write
        // landing in the same second as the select would otherwise fall outside the next window
        self::assertEquals(new \DateTimeImmutable('2026-09-24 09:59:55'), $this->watermark->since(0, self::NUM_WORKERS));
    }

    public function testTheWatermarkExpiresSoTheSweepComesBackAround(): void
    {
        $this->watermark->markCompleted(0, self::NUM_WORKERS, new \DateTimeImmutable());

        $ttl = $this->redis->ttl('metadata-dump:watermark:'.self::NUM_WORKERS.':0');

        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(DumpWatermark::TTL_SECONDS, $ttl);
    }

    public function testWorkersDoNotShareAWatermark(): void
    {
        $this->watermark->markCompleted(0, self::NUM_WORKERS, new \DateTimeImmutable('2026-09-24 10:00:00'));

        self::assertNotNull($this->watermark->since(0, self::NUM_WORKERS));
        self::assertNull($this->watermark->since(1, self::NUM_WORKERS), 'worker 1 owns different package ids and must not inherit worker 0 progress');
    }

    public function testReshardingInvalidatesTheWatermark(): void
    {
        $this->watermark->markCompleted(0, self::NUM_WORKERS, new \DateTimeImmutable('2026-09-24 10:00:00'));

        // id % numWorkers decides what a worker owns, so a different layout means worker 0 covers a
        // different set and the old progress vouches for ids it never dumped
        self::assertNull($this->watermark->since(0, self::NUM_WORKERS + 1));
    }

    private function forgetTestKeys(): void
    {
        foreach ([self::NUM_WORKERS, self::NUM_WORKERS + 1] as $numWorkers) {
            for ($workerId = 0; $workerId <= 1; $workerId++) {
                $this->redis->del('metadata-dump:watermark:'.$numWorkers.':'.$workerId);
            }
        }
    }
}
