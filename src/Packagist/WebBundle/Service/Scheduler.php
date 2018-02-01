<?php declare(strict_types=1);

namespace Packagist\WebBundle\Service;

use Doctrine\Common\Persistence\ManagerRegistry;
use Predis\Client as RedisClient;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Organization;
use Packagist\WebBundle\Entity\Job;

class Scheduler
{
    /** @var ManagerRegistry */
    private $doctrine;
    private $redis;

    public function __construct(RedisClient $redis, ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
        $this->redis = $redis;
    }

    public function scheduleUpdate($packageOrId, $updateEqualRefs = false, $deleteBefore = false, $executeAfter = null): Job
    {
        if ($packageOrId instanceof Package) {
            $packageOrId = $packageOrId->getId();
        } elseif (!is_int($packageOrId)) {
            throw new \UnexpectedValueException('Expected Package instance or int package id');
        }

        return $this->createJob('package:updates', ['id' => $packageOrId, 'update_equal_refs' => $updateEqualRefs, 'delete_before' => $deleteBefore], $packageOrId, $executeAfter);
    }

    public function hasPendingUpdateJob(int $packageId, $updateEqualRefs = false, $deleteBefore = false): bool
    {
        $result = $this->doctrine->getManager()->getConnection()->fetchAssoc('SELECT payload FROM job WHERE packageId = :package AND status = :status', [
            'package' => $packageId,
            'status' => Job::STATUS_QUEUED,
        ]);

        if ($result) {
            $payload = json_decode($result['payload'], true);
            if ($payload['update_equal_refs'] === $updateEqualRefs && $payload['delete_before'] === $deleteBefore) {
                return true;
            }
        }

        return false;
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
