<?php declare(strict_types=1);

namespace App\Service;

use Doctrine\Persistence\ManagerRegistry;
use Predis\Client as RedisClient;
use App\Entity\Package;
use App\Entity\Job;

class Scheduler
{
    private ManagerRegistry $doctrine;
    private RedisClient $redis;

    public function __construct(RedisClient $redis, ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
        $this->redis = $redis;
    }

    public function scheduleUpdate($packageOrId, bool $updateEqualRefs = false, bool $deleteBefore = false, ?\DateTimeInterface $executeAfter = null, bool $forceDump = false): Job
    {
        $updateEqualRefs = (bool) $updateEqualRefs;
        $deleteBefore = (bool) $deleteBefore;

        if ($packageOrId instanceof Package) {
            $packageOrId = $packageOrId->getId();
        } elseif (!is_int($packageOrId)) {
            throw new \UnexpectedValueException('Expected Package instance or int package id');
        }

        $pendingJobId = $this->getPendingUpdateJob($packageOrId, $updateEqualRefs, $deleteBefore);
        if ($pendingJobId) {
            $pendingJob = $this->doctrine->getManager()->getRepository(Job::class)->findOneBy(['id' => $pendingJobId]);

            // pending job will execute before the one we are trying to schedule so skip scheduling
            if (
                (!$pendingJob->getExecuteAfter() && $executeAfter)
                || ($pendingJob->getExecuteAfter() && $executeAfter && $pendingJob->getExecuteAfter() < $executeAfter)
            ) {
                return $pendingJob;
            }

            // neither job has executeAfter, so the pending one is equivalent to the one we are trying to schedule and we can skip scheduling
            if (!$pendingJob->getExecuteAfter() && !$executeAfter) {
                return $pendingJob;
            }

            // pending job will somehow execute after the one we are scheduling so we mark it complete and schedule a new job to run immediately
            $pendingJob->start();
            $pendingJob->complete(['status' => Job::STATUS_COMPLETED, 'message' => 'Another job is attempting to schedule immediately for this package, aborting scheduled-for-later update']);
            $this->doctrine->getManager()->flush($pendingJob);
        }

        return $this->createJob('package:updates', ['id' => $packageOrId, 'update_equal_refs' => $updateEqualRefs, 'delete_before' => $deleteBefore, 'force_dump' => $forceDump], $packageOrId, $executeAfter);
    }

    public function scheduleUserScopeMigration(int $userId, string $oldScope, string $newScope): Job
    {
        return $this->createJob('githubuser:migrate', ['id' => $userId, 'old_scope' => $oldScope, 'new_scope' => $newScope], $userId);
    }

    public function scheduleSecurityAdvisory(string $source, ?\DateTimeInterface $executeAfter = null): Job
    {
        return $this->createJob('security:advisory', ['source' => $source], null, $executeAfter);
    }

    private function getPendingUpdateJob(int $packageId, $updateEqualRefs = false, $deleteBefore = false)
    {
        $result = $this->doctrine->getManager()->getConnection()->fetchAssoc(
            'SELECT id, payload FROM job WHERE packageId = :package AND status = :status AND type = :type LIMIT 1',
            [
                'package' => $packageId,
                'type' => 'package:updates',
                'status' => Job::STATUS_QUEUED,
            ]
        );

        if ($result) {
            $payload = json_decode($result['payload'], true);
            if ($payload['update_equal_refs'] === $updateEqualRefs && $payload['delete_before'] === $deleteBefore) {
                return $result['id'];
            }
        }
    }

    /**
     * @return array [status => x, message => y]
     */
    public function getJobStatus(string $jobId): array
    {
        $data = $this->redis->get('job-'.$jobId);

        if ($data) {
            return json_decode($data, true);
        }

        return ['status' => 'running', 'message' => ''];
    }

    /**
     * @param  Job[]   $jobs
     * @return array[]
     */
    public function getJobsStatus(array $jobs): array
    {
        $results = [];

        foreach ($jobs as $job) {
            $jobId = $job->getId();
            $data = $this->redis->get('job-'.$jobId);

            if ($data) {
                $results[$jobId] = json_decode($data, true);
            } else {
                $results[$jobId] = ['status' => $job->getStatus()];
            }
        }

        return $results;
    }

    private function createJob(string $type, array $payload, $packageId = null, $executeAfter = null): Job
    {
        $jobId = bin2hex(random_bytes(20));

        $job = new Job();
        $job->setId($jobId);
        $job->setType($type);
        $job->setPayload($payload);
        $job->setCreatedAt(new \DateTime());
        if ($packageId) {
            $job->setPackageId($packageId);
        }
        if ($executeAfter instanceof \DateTimeInterface) {
            $job->setExecuteAfter($executeAfter);
        }

        $em = $this->doctrine->getManager();
        $em->persist($job);
        $em->flush();

        // trigger immediately if not scheduled for later
        if (!$job->getExecuteAfter()) {
            $this->redis->lpush('jobs', $job->getId());
        }

        return $job;
    }
}
