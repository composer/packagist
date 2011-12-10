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
class PackageRepository extends EntityRepository
{
    public function getStalePackages()
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p, v')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->leftJoin('p.versions', 'v')
            ->where('p.crawledAt IS NULL OR p.crawledAt < ?0')
            ->setParameters(array(new \DateTime('-1hour')));
        return $qb->getQuery()->getResult();
    }

    public function getStalePackagesForIndexing()
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p, v, t')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->leftJoin('p.versions', 'v')
            ->leftJoin('v.tags', 't')
            ->where('p.indexedAt IS NULL OR p.indexedAt < ?0')
            ->setParameters(array(new \DateTime('-1hour')));
        return $qb->getQuery()->getResult();
    }

    public function findOneByName($name)
    {
        $qb = $this->getBaseQueryBuilder()
            ->where('p.name = ?0')
            ->setParameters(array($name));
        return $qb->getQuery()->getSingleResult();
    }

    public function findByTag($name)
    {
        return $this->getBaseQueryBuilder()
            // eliminate maintainers & tags from the select, because of the groupBy
            ->select('p, v')
            ->where('t.name = ?0')
            ->setParameters(array($name));
    }

    public function findByIds(array $ids)
    {
        $qb = $this->getBaseQueryBuilder();

        return $qb->where(
            $qb->expr()->in('p.id', $ids)
        );
    }

    public function getQueryBuilderByMaintainer(User $user)
    {
        $qb = $this->getBaseQueryBuilder()
            // eliminate maintainers & tags from the select, because of the groupBy
            ->select('p, v')
            ->where('m.id = ?0')
            ->setParameters(array($user->getId()));
        return $qb;
    }

    public function getBaseQueryBuilder()
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p, v, t, m')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->leftJoin('p.versions', 'v')
            ->leftJoin('p.maintainers', 'm')
            ->leftJoin('v.tags', 't')
            ->orderBy('v.development', 'DESC')
            ->addOrderBy('v.releasedAt', 'DESC');
        return $qb;
    }
}
