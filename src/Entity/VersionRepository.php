<?php

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

use Doctrine\DBAL\Connection;
use Predis\Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class VersionRepository extends ServiceEntityRepository
{
    private $redis;

    protected $supportedLinkTypes = array(
        'require',
        'conflict',
        'provide',
        'replace',
        'devRequire',
        'suggest',
    );

    public function __construct(ManagerRegistry $registry, Client $redisCache)
    {
        parent::__construct($registry, Version::class);

        $this->redis = $redisCache;
    }

    public function remove(Version $version)
    {
        $em = $this->getEntityManager();
        $version->getPackage()->getVersions()->removeElement($version);
        $version->getPackage()->setCrawledAt(new \DateTime);
        $version->getPackage()->setUpdatedAt(new \DateTime);

        $em->getConnection()->executeQuery('DELETE FROM version_author WHERE version_id=:id', array('id' => $version->getId()));
        $em->getConnection()->executeQuery('DELETE FROM version_tag WHERE version_id=:id', array('id' => $version->getId()));
        $em->getConnection()->executeQuery('DELETE FROM link_suggest WHERE version_id=:id', array('id' => $version->getId()));
        $em->getConnection()->executeQuery('DELETE FROM link_conflict WHERE version_id=:id', array('id' => $version->getId()));
        $em->getConnection()->executeQuery('DELETE FROM link_replace WHERE version_id=:id', array('id' => $version->getId()));
        $em->getConnection()->executeQuery('DELETE FROM link_provide WHERE version_id=:id', array('id' => $version->getId()));
        $em->getConnection()->executeQuery('DELETE FROM link_require_dev WHERE version_id=:id', array('id' => $version->getId()));
        $em->getConnection()->executeQuery('DELETE FROM link_require WHERE version_id=:id', array('id' => $version->getId()));
        $em->getConnection()->executeQuery('DELETE FROM download WHERE id=:id AND type = :type', ['id' => $version->getId(), 'type' => Download::TYPE_VERSION]);

        $em->remove($version);
    }

    public function refreshVersions($versions)
    {
        $versionIds = [];
        foreach ($versions as $version) {
            $versionIds[] = $version->getId();
            $this->getEntityManager()->detach($version);
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
     * @param Version[] $versions
     */
    public function detachToArray(array $versions, array $versionData, bool $serializeForApi = false): array
    {
        $res = [];
        $em = $this->getEntityManager();
        foreach ($versions as $version) {
            $res[$version->getVersion()] = $version->toArray($versionData, $serializeForApi);
            $em->detach($version);
        }

        return $res;
    }

    public function getVersionData(array $versionIds)
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
                'authors' => [],
                'tags' => [],
            ];
        }

        foreach ($links as $link => $table) {
            $rows = $this->getEntityManager()->getConnection()->fetchAll(
                'SELECT version_id, packageName name, packageVersion version FROM '.$table.' WHERE version_id IN (:ids)',
                ['ids' => $versionIds],
                ['ids' => Connection::PARAM_INT_ARRAY]
            );
            foreach ($rows as $row) {
                $result[$row['version_id']][$link][] = $row;
            }
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAll(
            'SELECT va.version_id, name, email, homepage, role FROM author a JOIN version_author va ON va.author_id = a.id WHERE va.version_id IN (:ids)',
            ['ids' => $versionIds],
            ['ids' => Connection::PARAM_INT_ARRAY]
        );
        foreach ($rows as $row) {
            $versionId = $row['version_id'];
            unset($row['version_id']);
            $result[$versionId]['authors'][] = array_filter($row);
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAll(
            'SELECT vt.version_id, name FROM tag t JOIN version_tag vt ON vt.tag_id = t.id WHERE vt.version_id IN (:ids)',
            ['ids' => $versionIds],
            ['ids' => Connection::PARAM_INT_ARRAY]
        );
        foreach ($rows as $row) {
            $versionId = $row['version_id'];
            $result[$versionId]['tags'][] = $row['name'];
        }

        return $result;
    }

    public function getVersionMetadataForUpdate(Package $package)
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAll(
            'SELECT id, version, normalizedVersion, source, softDeletedAt, `authors` IS NULL as needs_author_migration FROM package_version v WHERE v.package_id = :id',
            ['id' => $package->getId()]
        );

        $versions = [];
        foreach ($rows as $row) {
            if ($row['source']) {
                $row['source'] = json_decode($row['source'], true);
            }
            $row['needs_author_migration'] = (int) $row['needs_author_migration'];
            $versions[strtolower($row['normalizedVersion'])] = $row;
        }

        return $versions;
    }

    public function getFullVersion($versionId)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('v', 't', 'a')
            ->from('App\Entity\Version', 'v')
            ->leftJoin('v.tags', 't')
            ->leftJoin('v.authors', 'a')
            ->where('v.id = :id')
            ->setParameter('id', $versionId);

        return $qb->getQuery()->getSingleResult();
    }

    /**
     * Returns the latest versions released
     *
     * @param string $vendor optional vendor filter
     * @param string $package optional vendor/package filter
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getQueryBuilderForLatestVersionWithPackage($vendor = null, $package = null)
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
            $qb->andWhere('p.name LIKE ?1');
            $qb->setParameter(1, $vendor.'/%');
        } elseif ($package) {
            $qb->andWhere('p.name = ?1')
                ->setParameter(1, $package);
        }

        return $qb;
    }

    public function getLatestReleases($count = 10)
    {
        if ($cached = $this->redis->get('new_releases')) {
            return json_decode($cached, true);
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
        $this->redis->setex('new_releases', 600, json_encode($res));

        return $res;
    }
}
