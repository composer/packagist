<?php declare(strict_types=1);

namespace App\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class JobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Job::class);
    }

    public function start(string $jobId): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        return 1 === $conn->executeUpdate('UPDATE job SET status = :status, startedAt = :now WHERE id = :id AND startedAt IS NULL', [
            'id' => $jobId,
            'status' => Job::STATUS_STARTED,
            'now' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markTimedOutJobs()
    {
        $conn = $this->getEntityManager()->getConnection();

        $conn->executeUpdate('UPDATE job SET status = :newstatus WHERE status = :status AND startedAt < :timeout', [
            'status' => Job::STATUS_STARTED,
            'newstatus' => Job::STATUS_TIMEOUT,
            'timeout' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
        ]);
    }

    public function getLastGitHubSyncJob(int $userId)
    {
        return $this->createQueryBuilder('j')
            ->where('j.packageId = :userId')
            ->andWhere('j.type = :type')
            ->orderBy('j.createdAt', 'DESC')
            ->getQuery()
            ->setMaxResults(1)
            ->setParameters(['userId' => $userId, 'type' => 'githubuser:migrate'])
            ->getOneOrNullResult();
    }

    public function getScheduledJobIds(): \Generator
    {
        $conn = $this->getEntityManager()->getConnection();

        $stmt = $conn->executeQuery('SELECT id FROM job WHERE status = :status AND (executeAfter IS NULL OR executeAfter <= :now) ORDER BY createdAt ASC', [
            'status' => Job::STATUS_QUEUED,
            'now' => date('Y-m-d H:i:s'),
        ]);

        while ($row = $stmt->fetch(\PDO::FETCH_COLUMN)) {
            yield $row;
        }
    }
}
