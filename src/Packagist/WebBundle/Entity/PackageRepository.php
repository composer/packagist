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
use Doctrine\ORM\AbstractQuery;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackageRepository extends EntityRepository
{
    /**
     * Lists all package names array(name => true)
     *
     * @var array
     */
    private $packageNames;

    public function packageExists($name)
    {
        $packages = $this->getPackageNames();
        return isset($packages[$name]);
    }

    public function getPackageNames()
    {
        if (null !== $this->packageNames) {
            return $this->packageNames;
        }

        $names = null;
        $apc = extension_loaded('apc');

        // TODO use container to set caching key and ttl
        if ($apc) {
            $names = apc_fetch('packagist_package_names');
        }

        if (!is_array($names)) {
            $names = array();

            $query = $this->getEntityManager()
                ->createQuery("SELECT p.name FROM Packagist\WebBundle\Entity\Package p");

            foreach ($query->getScalarResult() as $package) {
                $names[$package['name']] = true;
            }

            if ($apc) {
                apc_store('packagist_package_names', $names, 3600);
            }
        }

        return $this->packageNames = $names;
    }

    public function getLastUpdate()
    {
        $lastUpdate = $this->getEntityManager()->createQueryBuilder();
            ->select('MAX(p.updated_at)')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->getQuery()->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        return new \DateTime($lastUpdate);
    }

    public function getStalePackages()
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p, v')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->leftJoin('p.versions', 'v')
            ->where('p.crawledAt IS NULL')
            ->orWhere('(p.autoUpdated = false AND p.crawledAt < :crawled)')
            ->orWhere('(p.crawledAt < :autocrawled)')
            ->setParameter('crawled', new \DateTime('-1hour')) // crawl packages by hand once an hour
            ->setParameter('autocrawled', new \DateTime('-1week')); // crawl auto-updated packages just in case once a week

        return $qb->getQuery()->getResult();
    }

    public function getStalePackagesForIndexing()
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p, v, t')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->leftJoin('p.versions', 'v')
            ->leftJoin('v.tags', 't')
            ->where('p.indexedAt IS NULL OR p.indexedAt < p.crawledAt');

        return $qb->getQuery()->getResult();
    }

    public function findOneByName($name)
    {
        $qb = $this->getBaseQueryBuilder()
            ->where('p.name = ?0')
            ->setParameters(array($name));
        return $qb->getQuery()->getSingleResult();
    }

    public function getFullPackageByName($name)
    {
        $qb = $this->getBaseQueryBuilder()
            ->addSelect('a', 'req', 'rec', 'sug', 'rep', 'con', 'pro')
            ->leftJoin('v.authors', 'a')
            ->leftJoin('v.require', 'req')
            ->leftJoin('v.recommend', 'rec')
            ->leftJoin('v.suggest', 'sug')
            ->leftJoin('v.replace', 'rep')
            ->leftJoin('v.conflict', 'con')
            ->leftJoin('v.provide', 'pro')
            ->where('p.name = ?0')
            ->setParameters(array($name));
        return $qb->getQuery()->getSingleResult();
    }

    public function getFullPackages()
    {
        $qb = $this->getBaseQueryBuilder()
            ->addSelect('a', 'req', 'rec', 'sug', 'rep', 'con', 'pro')
            ->leftJoin('v.authors', 'a')
            ->leftJoin('v.require', 'req')
            ->leftJoin('v.recommend', 'rec')
            ->leftJoin('v.suggest', 'sug')
            ->leftJoin('v.replace', 'rep')
            ->leftJoin('v.conflict', 'con')
            ->leftJoin('v.provide', 'pro');

        return $qb->getQuery()->getResult();
    }

    public function findByTag($name)
    {
        return $this->getBaseQueryBuilder()
            // eliminate maintainers & tags from the select, because of the groupBy
            ->select('p, v')
            ->where('t.name = ?0')
            ->setParameters(array($name));
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
