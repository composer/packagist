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

namespace App\Tests\Service;

use App\Entity\Job;
use App\Entity\JobRepository;
use App\Logger\LogIdProcessor;
use App\Service\PackagePurgeWorker;
use App\Service\QueueWorker;
use Composer\IO\BufferIO;
use Composer\Util\Http\ResponseWarnings;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Graze\DogStatsD\Client as StatsDClient;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Predis\Client as Redis;
use Seld\Signal\SignalHandler;

class QueueWorkerTest extends TestCase
{
    private const WARNING = ['warning' => 'Repository is deprecated'];

    protected function tearDown(): void
    {
        ResponseWarnings::reset();
    }

    public function testComposerWarningsAreShownAgainInEachJob(): void
    {
        $outputs = [];
        $jobWorker = $this->createStub(PackagePurgeWorker::class);
        $jobWorker->method('process')->willReturnCallback(static function () use (&$outputs) {
            $io = new BufferIO();
            ResponseWarnings::output($io, 'https://example.org/packages.json', self::WARNING);
            $outputs[] = $io->getOutput();

            return ['status' => Job::STATUS_COMPLETED, 'message' => 'done'];
        });

        $worker = $this->createWorker($jobWorker);
        $this->processJob($worker, 'job1');
        $this->processJob($worker, 'job2');

        self::assertCount(2, $outputs);
        self::assertStringContainsString('Repository is deprecated', $outputs[0]);
        self::assertStringContainsString('Repository is deprecated', $outputs[1]);
    }

    public function testComposerWarningsAreOnlyShownOncePerProcessWithoutReset(): void
    {
        $io = new BufferIO();
        ResponseWarnings::output($io, 'https://example.org/packages.json', self::WARNING);
        self::assertStringContainsString('Repository is deprecated', $io->getOutput());

        $io = new BufferIO();
        ResponseWarnings::output($io, 'https://example.org/packages.json', self::WARNING);
        self::assertSame('', $io->getOutput());
    }

    private function createWorker(PackagePurgeWorker $jobWorker): QueueWorker
    {
        $repo = $this->createStub(JobRepository::class);
        $repo->method('start')->willReturn(true);
        $repo->method('find')->willReturnCallback(static fn (string $id) => new Job($id, 'package:purge', ['name' => 'test/pkg']));

        $em = $this->createStub(EntityManager::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('isOpen')->willReturn(true);

        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($em);

        return new QueueWorker(
            $this->createStub(Redis::class),
            $doctrine,
            new Logger('test'),
            ['package:purge' => $jobWorker],
            $this->createStub(StatsDClient::class),
            new LogIdProcessor(),
        );
    }

    private function processJob(QueueWorker $worker, string $jobId): void
    {
        $process = new \ReflectionMethod($worker, 'process');
        self::assertTrue($process->invoke($worker, $jobId, SignalHandler::create()));
    }
}
