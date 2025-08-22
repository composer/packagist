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

use App\SecurityAdvisory\FriendsOfPhpSecurityAdvisoriesSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;

/**
 * @extends ServiceEntityRepository<SecurityAdvisory>
 */
class SecurityAdvisoryRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private Client $redisCache,
    ) {
        parent::__construct($registry, SecurityAdvisory::class);
    }

    /**
     * @param string[] $packageNames
     *
     * @return SecurityAdvisory[]
     */
    public function getPackageAdvisoriesWithSources(array $packageNames, string $sourceName): array
    {
        if (\count($packageNames) === 0) {
            return [];
        }

        $advisories = $this
            ->createQueryBuilder('a')
            ->addSelect('s')
            ->innerJoin('a.sources', 's')
            ->innerJoin('a.sources', 'query')
            ->where('query.source = :source OR a.packageName IN (:packageNames)')
            ->setParameter('packageNames', $packageNames, ArrayParameterType::STRING)
            ->setParameter('source', $sourceName)
            ->getQuery()
            ->getResult();

        if ($sourceName !== FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME || \count($advisories) > 0) {
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

    /**
     * @return list<SecurityAdvisory>
     */
    public function findByRemoteId(string $source, string $id): array
    {
        return $this
            ->createQueryBuilder('a')
            ->addSelect('s')
            ->leftJoin('a.sources', 's')
            ->where('s.source = :source')
            ->andWhere('s.remoteId = :id')
            ->setParameter('source', $source)
            ->setParameter('id', $id)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<SecurityAdvisory>
     */
    public function findByPackageName(string $packageName): array
    {
        return $this
            ->createQueryBuilder('a')
            ->addSelect('s')
            ->leftJoin('a.sources', 's')
            ->where('a.packageName = :packageName')
            ->orderBy('a.reportedAt', 'DESC')
            ->setParameter('packageName', $packageName)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, array{advisoryId: string, packageName: string, remoteId: string, title: string, link: string|null, cve: string|null, affectedVersions: string, sources: array<array{name: string, remoteId: string}>, reportedAt: string, composerRepository: string|null}>
     */
    public function getPackageSecurityAdvisories(string $name): array
    {
        return $this->searchSecurityAdvisories([$name], 0)[$name] ?? [];
    }

    /**
     * @param string[] $packageNames
     *
     * @return array<string, array<string, array{advisoryId: string, packageName: string, remoteId: string, title: string, link: string|null, cve: string|null, affectedVersions: string, sources: array<array{name: string, remoteId: string}>, reportedAt: string, composerRepository: string|null}>> An array of packageName => advisoryId => advisory-data
     */
    public function searchSecurityAdvisories(array $packageNames, int $updatedSince): array
    {
        $packageNames = array_values(array_unique($packageNames));
        $filterByNames = \count($packageNames) > 0;
        $useCache = $filterByNames;
        $advisories = [];

        // optimize the search by package name as this is massively used by Composer
        if ($useCache) {
            $redisKeys = array_map(static fn ($pkg) => 'sec-adv:'.$pkg, $packageNames);
            $advisoryCache = $this->redisCache->mget($redisKeys);
            foreach ($packageNames as $index => $name) {
                if (isset($advisoryCache[$index])) {
                    unset($packageNames[$index]);

                    // cache as false means the name does not have any advisories
                    if ($advisoryCache[$index] !== 'false') {
                        $advisories[$name] = json_decode($advisoryCache[$index], true, \JSON_THROW_ON_ERROR);
                    }
                }
            }

            // check if we still need to query SQL
            $filterByNames = \count($packageNames) > 0;
        }

        if (!$useCache || $filterByNames) {
            $sql = 'SELECT s.packagistAdvisoryId as advisoryId, s.packageName, s.remoteId, s.title, s.link, s.cve, s.affectedVersions, s.source, s.reportedAt, s.composerRepository, sa.source sourceSource, sa.remoteId sourceRemoteId, s.severity
                FROM security_advisory s
                INNER JOIN security_advisory_source sa ON sa.securityAdvisory_id=s.id
                WHERE s.updatedAt >= :updatedSince '
                .($filterByNames ? ' AND s.packageName IN (:packageNames)' : '')
                .' ORDER BY '.($filterByNames ? 's.reportedAt DESC, ' : '').'s.id DESC';

            $params = ['updatedSince' => date('Y-m-d H:i:s', $updatedSince)];
            $types = [];
            if ($filterByNames) {
                $params['packageNames'] = $packageNames;
                $types['packageNames'] = ArrayParameterType::STRING;
            }

            $result = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params, $types);
            foreach ($result as $advisory) {
                $source = [
                    'name' => $advisory['sourceSource'],
                    'remoteId' => $advisory['sourceRemoteId'],
                ];
                unset($advisory['sourceSource'], $advisory['sourceRemoteId']);
                if (!isset($advisories[$advisory['packageName']][$advisory['advisoryId']])) {
                    $advisory['sources'] = [];
                    $advisories[$advisory['packageName']][$advisory['advisoryId']] = $advisory;
                }

                $advisories[$advisory['packageName']][$advisory['advisoryId']]['sources'][] = $source;
            }

            if ($useCache) {
                $cacheData = [];
                foreach ($packageNames as $name) {
                    // Cache for 7 days with a random 1-hour variance, the cache is busted by SecurityAdvisoryUpdateListener when advisories change
                    $this->redisCache->setex('sec-adv:'.$name, 604800 + random_int(0, 3600), isset($advisories[$name]) ? json_encode($advisories[$name], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) : 'false');
                }
            }
        }

        return $advisories;
    }

    /**
     * @param string[] $packageNames
     *
     * @return array<string, array<array{advisoryId: string, affectedVersions: string}>>
     */
    public function getAdvisoryIdsAndVersions(array $packageNames): array
    {
        $filterByNames = \count($packageNames) > 0;

        $sql = 'SELECT s.packagistAdvisoryId as advisoryId, s.packageName, s.affectedVersions
            FROM security_advisory s
            WHERE s.packageName IN (:packageNames)
            ORDER BY s.id DESC';

        $params['packageNames'] = $packageNames;
        $types['packageNames'] = ArrayParameterType::STRING;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            ['packageNames' => $packageNames],
            ['packageNames' => ArrayParameterType::STRING]
        );

        $results = [];
        foreach ($rows as $row) {
            $results[$row['packageName']][] = ['advisoryId' => $row['advisoryId'], 'affectedVersions' => $row['affectedVersions']];
        }

        return $results;
    }
}
