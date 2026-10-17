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

use App\Entity\Package;
use App\Package\V2DumpWindow;
use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Predis\Client;

class V2DumpWindowTest extends IntegrationTestCase
{
    private const int WORKER = 97;

    private Client $redis;
    private V2DumpWindow $window;

    protected function setUp(): void
    {
        parent::setUp();

        $redis = self::getContainer()->get('snc_redis.default');
        self::assertInstanceOf(Client::class, $redis);
        $this->redis = $redis;
        $this->redis->del('metadata-dump-v2:complete-pass:'.self::WORKER);
        $this->window = new V2DumpWindow($this->redis);
    }

    protected function tearDown(): void
    {
        $this->redis->del('metadata-dump-v2:complete-pass:'.self::WORKER);

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function provideAnchors(): iterable
    {
        yield 'no complete pass recorded yet' => [null, '-1 day'];
        yield 'a pass just completed' => ['now', '-1 hour'];
        yield 'the last complete pass was before an outage' => ['-3 hours', '-4 hours'];
        yield 'an outage longer than the lookback cap' => ['-3 days', '-1 day'];
        yield 'a pass recorded by a host whose clock runs ahead' => ['+10 minutes', '-1 hour'];
    }

    #[DataProvider('provideAnchors')]
    public function testTheWindowReachesBackFromTheLastCompletePass(?string $lastCompletePass, string $expectedSince): void
    {
        if ($lastCompletePass !== null) {
            $this->window->recordCompletePass(self::WORKER, new \DateTimeImmutable($lastCompletePass));
        }

        self::assertEqualsWithDelta(strtotime($expectedSince), $this->window->since(self::WORKER)->getTimestamp(), 2);
    }

    public function testAPackageThatWentStaleDuringAnOutageIsStillSelected(): void
    {
        $package = self::createPackage('acme/outage', 'https://example.com/acme/outage');
        $this->store($package);
        self::getEM()->getConnection()->executeStatement(
            'UPDATE package SET dumpedAtV2 = :dumped, crawledAt = :crawled WHERE id = :id',
            ['dumped' => date('Y-m-d H:i:s', (int) strtotime('-1 day')), 'crawled' => date('Y-m-d H:i:s', (int) strtotime('-90 minutes')), 'id' => $package->getId()],
        );
        $this->window->recordCompletePass(self::WORKER, new \DateTimeImmutable('-2 hours'));

        $repo = self::getEM()->getRepository(Package::class);
        self::assertNotContains($package->getId(), $repo->getStalePackagesForDumpingV2(since: new \DateTimeImmutable('-1 hour')), 'a window anchored on now has already lost it');
        self::assertContains($package->getId(), $repo->getStalePackagesForDumpingV2(since: $this->window->since(self::WORKER)));
    }
}
