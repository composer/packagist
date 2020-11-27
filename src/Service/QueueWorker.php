<?php declare(strict_types=1);

namespace App\Service;

use Predis\Client as Redis;
use Psr\Log\LoggerInterface;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Job;
use Seld\Signal\SignalHandler;
use Graze\DogStatsD\Client as StatsDClient;

class QueueWorker
{
    private $redis;
    private $logger;
    /** @var ManagerRegistry */
    private $doctrine;
    private $jobWorkers;
    private $processedJobs = 0;
    /** @var StatsDClient */
    private $statsd;

    public function __construct(Redis $redis, ManagerRegistry $doctrine, LoggerInterface $logger, array $jobWorkers, StatsDClient $statsd)
    {
        $this->redis = $redis;
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->jobWorkers = $jobWorkers;
        $this->statsd = $statsd;
    }

    /**
     * @param string|int $minPriority
     */
    public function processMessages(int $count)
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
        $this->doctrine->getManager()->getRepository(Job::class)->markTimedOutJobs();

        // check for timed out jobs every 20 min at least
        return time() + 1200;
    }

    private function checkForScheduledJobs(SignalHandler $signal): int
    {
        $em = $this->doctrine->getManager();
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
        $em = $this->doctrine->getManager();
        $repo = $em->getRepository(Job::class);
        if (!$repo->start($jobId)) {
            // race condition, some other worker caught the job first, aborting
            return false;
        }

        $job = $repo->findOneById($jobId);

        $this->logger->pushProcessor(function ($record) use ($job) {
            $record['extra']['job-id'] = $job->getId();

            return $record;
        });

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
        if (!$this->doctrine->getManager()->isOpen()) {
            $this->doctrine->resetManager();
        }

        // refetch objects in case the EM was reset during the job run
        $em = $this->doctrine->getManager();
        $repo = $em->getRepository(Job::class);

        if ($result['status'] === Job::STATUS_RESCHEDULE) {
            $job->reschedule($result['after']);
            $em->flush($job);

            $this->logger->reset();
            $this->logger->popProcessor();

            return true;
        }

        if (!isset($result['message']) || !isset($result['status'])) {
            throw new \LogicException('$result must be an array with at least status and message keys');
        }

        if (!in_array($result['status'], [Job::STATUS_COMPLETED, Job::STATUS_FAILED, Job::STATUS_ERRORED, Job::STATUS_PACKAGE_GONE, Job::STATUS_PACKAGE_DELETED], true)) {
            throw new \LogicException('$result[\'status\'] must be one of '.Job::STATUS_COMPLETED.' or '.Job::STATUS_FAILED.', '.$result['status'].' given');
        }

        if (isset($result['exception'])) {
            $result['exceptionMsg'] = $result['exception']->getMessage();
            $result['exceptionClass'] = get_class($result['exception']);
        }

        $job = $repo->findOneById($jobId);
        $job->complete($result);

        $this->redis->setex('job-'.$job->getId(), 600, json_encode($result));

        $em->flush($job);
        $em->clear();

        if ($result['status'] === Job::STATUS_FAILED) {
            $this->logger->warning('Job '.$job->getId().' failed', $result);
        } elseif ($result['status'] === Job::STATUS_ERRORED) {
            $this->logger->error('Job '.$job->getId().' errored', $result);
        }

        $this->logger->reset();
        $this->logger->popProcessor();

        return true;
    }
}
