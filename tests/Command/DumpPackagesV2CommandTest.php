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

use App\Command\DumpPackagesV2Command;
use App\Package\V2DumpOutcome;
use App\Package\V2DumpResult;
use App\Package\V2Dumper;
use App\Package\V2DumpWindow;
use App\Service\Locker;
use App\Tests\IntegrationTestCase;
use Doctrine\Persistence\ManagerRegistry;
use Graze\DogStatsD\Client as StatsDClient;
use Monolog\Logger;
use Predis\Client;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class DumpPackagesV2CommandTest extends IntegrationTestCase
{
    public function testEveryPassReportsAZeroCdnFailureCountForItsWorker(): void
    {
        // the composite monitor pairs the per-worker queue monitor with cdn_failure, which needs a
        // series per worker to match on even when nothing has failed yet
        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $this->store($package);

        $dumper = $this->createStub(V2Dumper::class);
        // an aborted run ends the command's loop after one pass
        $dumper->method('dump')->willReturn(new V2DumpResult(V2DumpOutcome::Aborted, [$package->getId()]));

        $increments = [];
        $statsd = $this->createStub(StatsDClient::class);
        $statsd->method('increment')->willReturnCallback(static function (string $metric, int $delta = 1, float $rate = 1.0, array $tags = []) use (&$increments, $statsd): StatsDClient {
            $increments[] = [$metric, $delta, $tags];

            return $statsd;
        });

        $redis = self::getContainer()->get('snc_redis.default');
        self::assertInstanceOf(Client::class, $redis);
        $cacheDir = sys_get_temp_dir().'/dump_v2_cmd_'.uniqid('', true);
        mkdir($cacheDir);

        try {
            $command = new DumpPackagesV2Command(
                $dumper,
                new Locker(self::getContainer()->get(ManagerRegistry::class)),
                self::getContainer()->get(ManagerRegistry::class),
                $cacheDir,
                new Logger('test'),
                $statsd,
                new V2DumpWindow($redis),
            );
            new Application()->addCommand($command);
            $tester = new CommandTester($command);
            $tester->execute([]);
        } finally {
            rmdir($cacheDir);
            $redis->del('metadata-dump-v2:oldest-pending:0');
        }

        self::assertContains(['packagist.metadata_dump.cdn_failure', 0, ['worker' => '0']], $increments);
    }
}
