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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class VersionRepository extends EntityRepository
{
    protected $supportedLinkTypes = array(
        'require',
        'conflict',
        'provide',
        'replace',
        'devRequire',
        'suggest',
    );

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
            ->orderBy('v.releasedAt', 'DESC');

        if ($vendor || $package) {
            $qb->innerJoin('v.package', 'p')
                ->addSelect('p');
        }

        if ($vendor) {
            $qb->andWhere('p.name LIKE ?0');
            $qb->setParameter(0, $vendor.'/%');
        } elseif ($package) {
            $qb->andWhere('p.name = ?0')
                ->setParameter(0, $package);
        }

        return $qb;
    }

    public function getLatestReleases($count = 10)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('v')
            ->from('Packagist\WebBundle\Entity\Version', 'v')
            ->where('v.development = false')
            ->andWhere('v.releasedAt < :now')
            ->orderBy('v.releasedAt', 'DESC')
            ->setMaxResults($count)
            ->setParameter('now', date('Y-m-d H:i:s'));

        return $qb->getQuery()->useResultCache(true, 900, 'new_releases')->getResult();
    }
}
