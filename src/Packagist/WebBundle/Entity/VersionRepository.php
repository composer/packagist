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

namespace Packagist\WebBundle\Entity;

use Doctrine\ORM\EntityRepository;
use Doctrine\DBAL\Connection;
use Predis\Client;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class VersionRepository extends EntityRepository
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

    public function setRedis(Client $client)
    {
        $this->redis = $client;
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

        return $result;
    }

    public function getFullVersion($versionId)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('v', 't', 'a')
            ->from('Packagist\WebBundle\Entity\Version', 'v')
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
            ->from('Packagist\WebBundle\Entity\Version', 'v')
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
            ->from('Packagist\WebBundle\Entity\Version', 'v')
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
