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

use App\Model\VersionIdCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 *
 * @extends ServiceEntityRepository<Version>
 *
 * @phpstan-import-type VersionArray from Version
 */
class VersionRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private Client $redisCache,
        private VersionIdCache $versionIdCache,
    ) {
        parent::__construct($registry, Version::class);
    }

    // make protected method public to allow usage in Package class
    public function getEntityManager(): EntityManagerInterface
    {
        return parent::getEntityManager();
    }

    public function remove(Version $version): void
    {
        $em = $this->getEntityManager();
        $package = $version->getPackage();
        $package->getVersions()->removeElement($version);
        $package->setCrawledAt(new \DateTimeImmutable());
        $package->setUpdatedAt(new \DateTimeImmutable());
        $em->persist($package);

        $this->versionIdCache->deleteVersion($package, $version);

        $em->getConnection()->executeQuery('DELETE FROM version_tag WHERE version_id=:id', ['id' => $version->getId()]);
        $em->getConnection()->executeQuery('DELETE FROM link_suggest WHERE version_id=:id', ['id' => $version->getId()]);
        $em->getConnection()->executeQuery('DELETE FROM link_conflict WHERE version_id=:id', ['id' => $version->getId()]);
        $em->getConnection()->executeQuery('DELETE FROM link_replace WHERE version_id=:id', ['id' => $version->getId()]);
        $em->getConnection()->executeQuery('DELETE FROM link_provide WHERE version_id=:id', ['id' => $version->getId()]);
        $em->getConnection()->executeQuery('DELETE FROM link_require_dev WHERE version_id=:id', ['id' => $version->getId()]);
        $em->getConnection()->executeQuery('DELETE FROM link_require WHERE version_id=:id', ['id' => $version->getId()]);
        $em->getConnection()->executeQuery('DELETE FROM download WHERE id=:id AND type = :type', ['id' => $version->getId(), 'type' => Download::TYPE_VERSION]);
        $em->getConnection()->executeQuery('DELETE FROM php_stat WHERE version=:version AND depth = :depth AND package_id=:packageId', ['version' => $version->getId(), 'depth' => PhpStat::DEPTH_EXACT, 'packageId' => $version->getPackage()->getId()]);

        $em->remove($version);
    }

    /**
     * @param Version[] $versions
     *
     * @return Version[]
     */
    public function refreshVersions(array $versions): array
    {
        $versionIds = [];
        foreach ($versions as $version) {
            $versionIds[] = $version->getId();
        }

        $refreshedVersions = $this->findBy(['id' => $versionIds]);
        $versionsById = [];
        foreach ($refreshedVersions as $version) {
            $versionsById[$version->getId()] = $version;
        }

        $refreshedVersions = [];
        foreach ($versions as $version) {
            $refreshedVersions[] = $versionsById[$version->getId()];
        }

        return $refreshedVersions;
    }

    /**
     * @param Version[]   $versions
     * @param VersionData $versionData
     *
     * @return array<string, VersionArray>
     */
    public function detachToArray(array $versions, array $versionData, bool $serializeForApi = false): array
    {
        $res = [];
        $em = $this->getEntityManager();
        foreach ($versions as $version) {
            $res[$version->getVersion()] = $version->toArray($versionData, $serializeForApi);
        }

        return $res;
    }

    /**
     * @param int[] $versionIds
     *
     * @return VersionData
     */
    public function getVersionData(array $versionIds): array
    {
        $links = [
            'require' => 'link_require',
            'devRequire' => 'link_require_dev',
            'suggest' => 'link_suggest',
            'conflict' => 'link_conflict',
            'provide' => 'link_provide',
            'replace' => 'link_replace',
        ];

        $result = [];
        foreach ($versionIds as $id) {
            $result[$id] = [
                'require' => [],
                'devRequire' => [],
                'suggest' => [],
                'conflict' => [],
                'provide' => [],
                'replace' => [],
                'tags' => [],
            ];
        }

        foreach ($links as $link => $table) {
            $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
                'SELECT version_id, packageName name, packageVersion version FROM '.$table.' WHERE version_id IN (:ids)',
                ['ids' => $versionIds],
                ['ids' => ArrayParameterType::INTEGER]
            );
            foreach ($rows as $row) {
                $result[$row['version_id']][$link][] = $row;
            }
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT vt.version_id, name FROM tag t JOIN version_tag vt ON vt.tag_id = t.id WHERE vt.version_id IN (:ids)',
            ['ids' => $versionIds],
            ['ids' => ArrayParameterType::INTEGER]
        );
        foreach ($rows as $row) {
            $versionId = $row['version_id'];
            $result[$versionId]['tags'][] = $row['name'];
        }

        return $result;
    }

    /**
     * @return ExistingVersionsForUpdate
     */
    public function getVersionMetadataForUpdate(Package $package): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, version, normalizedVersion, source, softDeletedAt FROM package_version v WHERE v.package_id = :id',
            ['id' => $package->getId()]
        );

        $versions = [];
        foreach ($rows as $row) {
            if ($row['source']) {
                $row['source'] = json_decode($row['source'], true);
            }
            $versions[strtolower($row['normalizedVersion'])] = $row;
        }

        return $versions;
    }

    /**
     * @throws \Doctrine\ORM\NoResultException
     */
    public function getFullVersion(int $versionId): Version
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('v', 't')
            ->from('App\Entity\Version', 'v')
            ->leftJoin('v.tags', 't')
            ->where('v.id = :id')
            ->setParameter('id', $versionId);

        return $qb->getQuery()->getSingleResult();
    }

    /**
     * Returns the latest versions released
     *
     * @param string $vendor  optional vendor filter
     * @param string $package optional vendor/package filter
     */
    public function getQueryBuilderForLatestVersionWithPackage(?string $vendor = null, ?string $package = null): QueryBuilder
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('v')
            ->from('App\Entity\Version', 'v')
            ->where('v.development = false')
            ->andWhere('v.releasedAt <= ?0')
            ->orderBy('v.releasedAt', 'DESC');
        $qb->setParameter(0, date('Y-m-d H:i:s'));

        if ($vendor || $package) {
            $qb->innerJoin('v.package', 'p')
                ->addSelect('p');
        }

        if ($vendor) {
            $qb->andWhere('p.vendor = ?1');
            $qb->setParameter(1, $vendor);
        } elseif ($package) {
            $qb->andWhere('p.name = ?1')
                ->setParameter(1, $package);
        }

        return $qb;
    }

    /**
     * @return array<array{name: string, version: string, description: string|null}>
     */
    public function getLatestReleases(int $count = 10): array
    {
        if ($cached = $this->redisCache->get('new_releases')) {
            return json_decode($cached, true, flags: \JSON_THROW_ON_ERROR);
        }

        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('v.name, v.version, v.description')
            ->from('App\Entity\Version', 'v')
            ->where('v.development = false')
            ->andWhere('v.releasedAt < :now')
            ->orderBy('v.releasedAt', 'DESC')
            ->setMaxResults($count)
            ->setParameter('now', date('Y-m-d H:i:s'));

        $res = $qb->getQuery()->getResult();
        $this->redisCache->setex('new_releases', 600, json_encode($res, \JSON_THROW_ON_ERROR));

        return $res;
    }

    public function getTotal(): int
    {
        // it seems the GROUP BY 1=1 helps mysql figure out a faster way to get the count by using another index
        $sql = 'SELECT COUNT(*) count FROM `package_version` GROUP BY 1=1';

        $stmt = $this->getEntityManager()->getConnection()
            ->executeCacheQuery(
                $sql,
                [],
                [],
                new QueryCacheProfile(86400, 'total_package_versions', $this->getEntityManager()->getConfiguration()->getResultCache())
            );
        $result = $stmt->fetchAllAssociative();
        $stmt->free();

        return (int) $result[0]['count'];
    }

    /**
     * @return array<int<0, max>, array{count: int, year: int|null, month: int|null}>
     */
    public function getCountByYearMonth(): array
    {
        $sql = 'SELECT COUNT(*) count, YEAR(releasedAt) year, MONTH(releasedAt) month FROM `package_version` GROUP BY year, month';

        $stmt = $this->getEntityManager()->getConnection()
            ->executeCacheQuery(
                $sql,
                [],
                [],
                new QueryCacheProfile(3600, 'package_versions_count_by_year_month', $this->getEntityManager()->getConfiguration()->getResultCache())
            );
        $result = $stmt->fetchAllAssociative();
        $stmt->free();

        return $result;
    }
}
