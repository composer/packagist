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
use Monolog\Handler\TestHandler;
use Monolog\Level;
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

    public function testFailedJobIsLoggedAsWarning(): void
    {
        $records = $this->processFailedJob([]);

        self::assertCount(1, $records);
        self::assertSame(Level::Warning, $records[0]->level);
    }

    public function testFailedJobIsLoggedAtTheLevelTheJobWorkerAskedFor(): void
    {
        $records = $this->processFailedJob(['logLevel' => Level::Notice]);

        self::assertCount(1, $records);
        self::assertSame(Level::Notice, $records[0]->level);
        self::assertSame('Job job1 failed', $records[0]->message);
        self::assertArrayNotHasKey('logLevel', $records[0]->context);
    }

    /**
     * @param array<string, mixed> $extraResult
     *
     * @return list<\Monolog\LogRecord> the records logged at notice level or above
     */
    private function processFailedJob(array $extraResult): array
    {
        $jobWorker = $this->createStub(PackagePurgeWorker::class);
        $jobWorker->method('process')->willReturn(['status' => Job::STATUS_FAILED, 'message' => 'failed', 'exception' => new \RuntimeException('failed')] + $extraResult);

        // QueueWorker resets the logger after each job, which would clear the records
        $handler = new class extends TestHandler {
            public function reset(): void
            {
            }
        };
        $this->processJob($this->createWorker($jobWorker, new Logger('test', [$handler])), 'job1');

        return array_values(array_filter($handler->getRecords(), static fn ($record) => $record->level->isHigherThan(Level::Info)));
    }

    private function createWorker(PackagePurgeWorker $jobWorker, Logger $logger = new Logger('test')): QueueWorker
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
            $logger,
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
