<?php declare(strict_types=1);

namespace App\Entity;

use App\SecurityAdvisory\FriendsOfPhpSecurityAdvisoriesSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecurityAdvisory>
 */
class SecurityAdvisoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityAdvisory::class);
    }

    /**
     * @param string[] $packageNames
     * @return SecurityAdvisory[]
     */
    public function getPackageAdvisoriesWithSources(array $packageNames, string $sourceName): array
    {
        if (count($packageNames) === 0) {
            return [];
        }

        $advisories = $this
            ->createQueryBuilder('a')
            ->addSelect('s')
            ->innerJoin('a.sources', 's')
            ->innerJoin('a.sources', 'query')
            ->where('query.source = :source OR a.packageName IN (:packageNames)')
            ->setParameter('packageNames', $packageNames, Connection::PARAM_STR_ARRAY)
            ->setParameter('source', $sourceName)
            ->getQuery()
            ->getResult();

        if ($sourceName !== FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME || count($advisories) > 0) {
            return $advisories;
        }

        // FriendsOfPHP advisories were not migrated yet
        // Remove this once everything is set up
        $allAdvisories = $this->getAllWithSources();
        foreach ($allAdvisories as $advisory) {
            $advisory->setupSource();
        }

        $this->getEntityManager()->flush();

        return $this->getPackageAdvisoriesWithSources($packageNames, $sourceName);
    }

    /**
     * @return SecurityAdvisory[]
     */
    private function getAllWithSources(): array
    {
        return $this
            ->createQueryBuilder('a')
            ->addSelect('s')
            ->leftJoin('a.sources', 's')
            ->getQuery()
            ->getResult();
    }

    public function getPackageSecurityAdvisories(string $name): array
    {
        $sql = 'SELECT s.*, sa.source
            FROM security_advisory s
            INNER JOIN security_advisory_source sa ON sa.securityAdvisory_id=s.id
            WHERE s.packageName = :name
            ORDER BY s.reportedAt DESC, s.id DESC';

        $entries = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, ['name' => $name]);
        $result = [];
        foreach ($entries as $entry) {
            if (!isset($result[$entry['id']])) {
                $result[$entry['id']] = $entry;
                $result[$entry['id']]['sources'] = [];
            }

            $result[$entry['id']]['sources'][] = $entry['source'];
        }

        return $result;
    }

    /**
     * @param string[] $packageNames
     */
    public function searchSecurityAdvisories(array $packageNames, int $updatedSince): array
    {
        $filterByNames = count($packageNames) > 0;

        $sql = 'SELECT s.packagistAdvisoryId as advisoryId, s.packageName, s.remoteId, s.title, s.link, s.cve, s.affectedVersions, s.source, s.reportedAt, s.composerRepository, sa.source sourceSource, sa.remoteId sourceRemoteId
            FROM security_advisory s
            INNER JOIN security_advisory_source sa ON sa.securityAdvisory_id=s.id
            WHERE s.updatedAt >= :updatedSince '.
            ($filterByNames ? ' AND s.packageName IN (:packageNames)' : '')
            .' ORDER BY s.id DESC';

        $params = ['updatedSince' => date('Y-m-d H:i:s', $updatedSince)];
        $types = [];
        if ($filterByNames) {
            $params['packageNames'] = $packageNames;
            $types['packageNames'] = Connection::PARAM_STR_ARRAY;
        }

        return $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params, $types);
    }

    /**
     * @param string[] $packageNames
     * @return array<string, array<array{advisoryId: string, affectedVersions: string}>>
     */
    public function getAdvisoryIdsAndVersions(array $packageNames): array
    {
        $filterByNames = count($packageNames) > 0;

        $sql = 'SELECT s.packagistAdvisoryId as advisoryId, s.packageName, s.affectedVersions
            FROM security_advisory s
            WHERE s.packageName IN (:packageNames)
            ORDER BY s.id DESC';

        $params['packageNames'] = $packageNames;
        $types['packageNames'] = Connection::PARAM_STR_ARRAY;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            ['packageNames' => $packageNames],
            ['packageNames' => Connection::PARAM_STR_ARRAY]
        );

        $results = [];
        foreach ($rows as $row) {
            $results[$row['packageName']] = ['id' => $row['id'], 'affectedVersions' => $row['affectedVersions']];
        }

        return $results;
    }
}
