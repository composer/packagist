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
        $this->redis->del('metadata-dump-v2:oldest-pending:'.self::WORKER);
        $this->window = new V2DumpWindow($this->redis);
    }

    protected function tearDown(): void
    {
        $this->redis->del('metadata-dump-v2:oldest-pending:'.self::WORKER);

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function provideAnchors(): iterable
    {
        yield 'no anchor recorded yet' => [null, '-1 day'];
        yield 'nothing left pending' => ['now', '-10 minutes'];
        yield 'a request left pending through an outage' => ['-3 hours', '-190 minutes'];
        yield 'pending for longer than the lookback cap' => ['-3 days', '-1 day'];
        yield 'an anchor recorded by a host whose clock runs ahead' => ['+10 minutes', '-10 minutes'];
    }

    #[DataProvider('provideAnchors')]
    public function testTheWindowReachesBackFromTheAnchor(?string $anchor, string $expectedSince): void
    {
        if ($anchor !== null) {
            $this->window->record(self::WORKER, new \DateTimeImmutable($anchor));
        }

        self::assertEqualsWithDelta(strtotime($expectedSince), $this->window->since(self::WORKER)->getTimestamp(), 2);
    }

    public function testTheAnchorIsTheSelectTimeWhenNothingIsLeftPending(): void
    {
        $selectedAt = new \DateTimeImmutable('-5 seconds');

        self::assertEquals($selectedAt, V2DumpWindow::anchorFor([], $selectedAt));
        self::assertEquals($selectedAt, V2DumpWindow::anchorFor([1 => null], $selectedAt), 'never-dumped packages are found whatever the window');
    }

    public function testTheAnchorIsTheOldestPendingRequest(): void
    {
        $selectedAt = new \DateTimeImmutable();

        $anchor = V2DumpWindow::anchorFor([1 => date('Y-m-d H:i:s', (int) strtotime('-20 minutes')), 2 => date('Y-m-d H:i:s', (int) strtotime('-2 hours')), 3 => null], $selectedAt);

        self::assertEqualsWithDelta(strtotime('-2 hours'), $anchor->getTimestamp(), 2);
    }

    public function testAPackageLeftPendingThroughAnOutageIsStillSelected(): void
    {
        $package = self::createPackage('acme/outage', 'https://example.com/acme/outage');
        $this->store($package);
        $requestedAt = date('Y-m-d H:i:s', (int) strtotime('-3 hours'));
        self::getEM()->getConnection()->executeStatement(
            'UPDATE package SET dumpedAtV2 = :dumped, dumpRequestedAt = :requested WHERE id = :id',
            ['dumped' => date('Y-m-d H:i:s', (int) strtotime('-1 day')), 'requested' => $requestedAt, 'id' => $package->getId()],
        );

        $repo = self::getEM()->getRepository(Package::class);
        $pending = array_intersect_key($repo->getStalePackagesForDumpingV2(since: new \DateTimeImmutable('-4 hours')), [$package->getId() => true]);
        $this->window->record(self::WORKER, V2DumpWindow::anchorFor($pending, new \DateTimeImmutable()));

        self::assertNotContains($package->getId(), array_keys($repo->getStalePackagesForDumpingV2(since: new \DateTimeImmutable('-1 hour'))), 'a window anchored on now has already lost it');
        self::assertArrayHasKey($package->getId(), $repo->getStalePackagesForDumpingV2(since: $this->window->since(self::WORKER)));
    }
}
