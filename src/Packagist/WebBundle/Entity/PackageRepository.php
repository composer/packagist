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
    public function search($query)
    {
        $pattern = '%'.str_replace(' ', '%', $query).'%';

        $qb = $this->createQueryBuilder('e');
        $qb->where($qb->expr()->orx(
            $qb->expr()->like('e.name', ':name'),
            $qb->expr()->like('e.description', ':description')
        ));
        $qb->setParameters(array('name' => $pattern, 'description' => $pattern));
        return $qb->getQuery()->execute();
    }
    
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

    public function findAll()
    {
        return $this->getBaseQueryBuilder()->getQuery()->getResult();
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
        $qb = $this->getBaseQueryBuilder()
            // eliminate maintainers & tags from the select, because of the groupBy
            ->select('p, v')
            ->where('t.name = ?0')
            ->setParameters(array($name));
        return $qb->getQuery()->getResult();
    }

    public function findByMaintainer(User $user)
    {
        $qb = $this->getBaseQueryBuilder()
            // eliminate maintainers & tags from the select, because of the groupBy
            ->select('p, v')
            ->where('m.id = ?0')
            ->setParameters(array($user->getId()));
        return $qb->getQuery()->getResult();
    }

    private function getBaseQueryBuilder()
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p, v, t, m')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->leftJoin('p.versions', 'v')
            ->leftJoin('p.maintainers', 'm')
            ->leftJoin('v.tags', 't')
            ->orderBy('v.releasedAt', 'DESC');
        return $qb;
    }
}
