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

use Doctrine\Persistence\ManagerRegistry;
use Predis\Client as RedisClient;
use App\Entity\Package;
use App\Entity\Job;

class Scheduler
{
    use \App\Util\DoctrineTrait;

    public function __construct(
        private RedisClient $redis,
        private ManagerRegistry $doctrine
    ) {
    }

    /**
     * @param Package|int $packageOrId
     */
    public function scheduleUpdate($packageOrId, bool $updateEqualRefs = false, bool $deleteBefore = false, ?\DateTimeInterface $executeAfter = null, bool $forceDump = false): Job
    {
        if ($packageOrId instanceof Package) {
            $packageOrId = $packageOrId->getId();
        } elseif (!is_int($packageOrId)) {
            throw new \UnexpectedValueException('Expected Package instance or int package id');
        }

        $pendingJobId = $this->getPendingUpdateJob($packageOrId, $updateEqualRefs, $deleteBefore);
        if ($pendingJobId && ($pendingJob = $this->getEM()->getRepository(Job::class)->findOneBy(['id' => $pendingJobId])) !== null) {
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
            $this->getEM()->flush($pendingJob);
        }

        return $this->createJob('package:updates', ['id' => $packageOrId, 'update_equal_refs' => $updateEqualRefs, 'delete_before' => $deleteBefore, 'force_dump' => $forceDump], $packageOrId, $executeAfter);
    }

    public function scheduleUserScopeMigration(int $userId, string $oldScope, string $newScope): Job
    {
        return $this->createJob('githubuser:migrate', ['id' => $userId, 'old_scope' => $oldScope, 'new_scope' => $newScope], $userId);
    }

    public function scheduleSecurityAdvisory(string $source, int $packageId, ?\DateTimeInterface $executeAfter = null): Job
    {
        return $this->createJob('security:advisory', ['source' => $source], $packageId, $executeAfter);
    }

    private function getPendingUpdateJob(int $packageId, bool $updateEqualRefs = false, bool $deleteBefore = false): ?string
    {
        $result = $this->getEM()->getConnection()->fetchAssociative(
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

        return null;
    }

    /**
     * @return array{status: string, message: string}
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
     * @return array<string, array{status: mixed}>
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

    private function createJob(string $type, array $payload, ?int $packageId = null, ?\DateTimeInterface $executeAfter = null): Job
    {
        $jobId = bin2hex(random_bytes(20));

        $job = new Job($jobId, $type, $payload);
        if ($packageId) {
            $job->setPackageId($packageId);
        }
        if ($executeAfter instanceof \DateTimeInterface) {
            $job->setExecuteAfter($executeAfter);
        }

        $em = $this->getEM();
        $em->persist($job);
        $em->flush();

        // trigger immediately if not scheduled for later
        if (!$job->getExecuteAfter()) {
            $this->redis->lpush('jobs', $job->getId());
        }

        return $job;
    }
}
