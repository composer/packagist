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

namespace App\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Job>
 */
class JobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Job::class);
    }

    public function start(string $jobId): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        return 1 === $conn->executeStatement('UPDATE job SET status = :status, startedAt = :now WHERE id = :id AND startedAt IS NULL', [
            'id' => $jobId,
            'status' => Job::STATUS_STARTED,
            'now' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markTimedOutJobs(): void
    {
        $conn = $this->getEntityManager()->getConnection();

        $conn->executeStatement('UPDATE job SET status = :newstatus WHERE status = :status AND startedAt < :timeout', [
            'status' => Job::STATUS_STARTED,
            'newstatus' => Job::STATUS_TIMEOUT,
            'timeout' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
        ]);
    }

    /**
     * @return Job<GitHubUserMigrateJob>|null
     */
    public function getLastGitHubSyncJob(int $userId): ?Job
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

        while ($row = $stmt->fetchAssociative()) {
            yield $row['id'];
        }
    }

    /**
     * @return Job<AnyJob>|null
     */
    public function findLatestExecutedJob(int $packageId, string $type): ?Job
    {
        $conn = $this->getEntityManager()->getConnection();

        $id = $conn->fetchOne(
            'SELECT id FROM job WHERE packageId = :package AND status IN (:statuses) AND type = :type ORDER BY createdAt DESC',
            [
                'package' => $packageId,
                'statuses' => [Job::STATUS_COMPLETED, Job::STATUS_ERRORED, Job::STATUS_FAILED],
                'type' => $type,
            ],
            ['statuses' => Connection::PARAM_STR_ARRAY]
        );
        if ($id) {
            return $this->find($id);
        }

        return null;
    }
}
