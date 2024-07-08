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

namespace App\Service;

use App\Logger\LogIdProcessor;
use Monolog\LogRecord;
use Predis\Client as Redis;
use Monolog\Logger;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Job;
use Seld\Signal\SignalHandler;
use Graze\DogStatsD\Client as StatsDClient;
use TypeError;
use Webmozart\Assert\Assert;

class QueueWorker
{
    use \App\Util\DoctrineTrait;

    private int $processedJobs = 0;

    public function __construct(
        private Redis $redis,
        private ManagerRegistry $doctrine,
        private Logger $logger,
        /** @var array<string, UpdaterWorker|GitHubUserMigrationWorker|SecurityAdvisoryWorker> */
        private array $jobWorkers,
        private StatsDClient $statsd,
        private readonly LogIdProcessor $logIdProcessor,
    ) {
    }

    public function processMessages(int $count): void
    {
        $signal = SignalHandler::create(null, $this->logger);

        $this->logger->info('Waiting for new messages');

        $nextTimedoutJobCheck = $this->checkForTimedoutJobs();
        $nextScheduledJobCheck = $this->checkForScheduledJobs($signal);

        while ($this->processedJobs++ < $count) {
            if ($signal->isTriggered()) {
                $this->logger->debug('Signal received, aborting');
                break;
            }

            $now = time();
            if ($nextTimedoutJobCheck <= $now) {
                $nextTimedoutJobCheck = $this->checkForTimedoutJobs();
            }
            if ($nextScheduledJobCheck <= $now) {
                $nextScheduledJobCheck = $this->checkForScheduledJobs($signal);
            }

            $result = $this->redis->brpop('jobs', 10);
            if (!$result) {
                continue;
            }

            $jobId = $result[1];
            $this->process($jobId, $signal);
        }
    }

    private function checkForTimedoutJobs(): int
    {
        $this->getEM()->getRepository(Job::class)->markTimedOutJobs();

        // check for timed out jobs every 20 min at least
        return time() + 1200;
    }

    private function checkForScheduledJobs(SignalHandler $signal): int
    {
        $em = $this->getEM();
        $repo = $em->getRepository(Job::class);

        foreach ($repo->getScheduledJobIds() as $jobId) {
            if ($this->process($jobId, $signal)) {
                $this->processedJobs++;
            }
        }

        // check for scheduled jobs every 5 minutes at least
        return time() + 300;
    }

    /**
     * Calls the configured processor with the job and a callback that must be called to mark the job as processed
     */
    private function process(string $jobId, SignalHandler $signal): bool
    {
        $em = $this->getEM();
        $repo = $em->getRepository(Job::class);
        if (!$repo->start($jobId)) {
            // race condition, some other worker caught the job first, aborting
            return false;
        }

        /** @var Job<AnyJob>|null $job */
        $job = $repo->find($jobId);
        if (null === $job) {
            throw new \LogicException('At this point a job should always be found');
        }

        $this->logIdProcessor->startJob($job->getId());

        $expectedStart = $job->getExecuteAfter() ?: $job->getCreatedAt();
        $start = microtime(true);
        $this->statsd->timing('worker.queue.waittime', round(($start - $expectedStart->getTimestamp()) * 1000, 4), [
            'jobType' => $job->getType(),
        ]);

        $processor = $this->jobWorkers[$job->getType()];

        $this->logger->reset();
        $this->logger->debug('Processing ' . $job->getType() . ' job', ['job' => $job->getPayload()]);

        try {
            $result = $processor->process($job, $signal);
        } catch (\Throwable $e) {
            if ($e instanceof TypeError) {
                $this->logger->error('TypeError: '.$e->getMessage(), ['exception' => $e]);
                $this->statsd->increment('worker.queue.processed', 1, 1, [
                    'jobType' => $job->getType(),
                    'status' => 'type_errored',
                ]);
            }
            $result = [
                'status' => Job::STATUS_ERRORED,
                'message' => 'An unexpected failure occurred',
                'exception' => $e,
            ];
        }

        $this->statsd->increment('worker.queue.processed', 1, 1, [
            'jobType' => $job->getType(),
            'status' => $result['status'],
        ]);

        $this->statsd->timing('worker.queue.processtime', round((microtime(true) - $start) * 1000, 4), [
            'jobType' => $job->getType(),
        ]);

        // If an exception is thrown during a transaction the EntityManager is closed
        // and we won't be able to update the job or handle future jobs
        if (!$this->getEM()->isOpen()) {
            $this->doctrine->resetManager();
        }

        // reset EM for safety to avoid flushing anything not flushed during the job, and refetch objects
        $em = $this->getEM();
        $em->clear();
        $repo = $em->getRepository(Job::class);
        $job = $repo->find($jobId);
        if (null === $job) {
            throw new \LogicException('At this point a job should always be found');
        }

        if ($result['status'] === Job::STATUS_RESCHEDULE) {
            Assert::keyExists($result, 'after', message: '$result must have an "after" key when returning a reschedule status.');
            $job->reschedule($result['after']);
            $em->persist($job);
            $em->flush();

            $this->logger->reset();

            return true;
        }

        Assert::keyExists($result, 'message');
        Assert::keyExists($result, 'status');
        Assert::inArray($result['status'], [Job::STATUS_COMPLETED, Job::STATUS_FAILED, Job::STATUS_ERRORED, Job::STATUS_PACKAGE_GONE, Job::STATUS_PACKAGE_DELETED]);

        if (isset($result['exception'])) {
            $result['exceptionMsg'] = $result['exception']->getMessage();
            $result['exceptionClass'] = get_class($result['exception']);
        }

        $job->complete($result);
        $em->persist($job);

        $this->redis->setex('job-'.$job->getId(), 600, json_encode($result));

        $em->flush();
        $em->clear();

        if ($result['status'] === Job::STATUS_FAILED) {
            $this->logger->warning('Job '.$job->getId().' failed', $result);
        } elseif ($result['status'] === Job::STATUS_ERRORED) {
            $this->logger->error('Job '.$job->getId().' errored', $result);
        }

        $this->logger->reset();

        return true;
    }
}
