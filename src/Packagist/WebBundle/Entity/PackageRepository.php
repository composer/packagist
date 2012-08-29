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
use Doctrine\ORM\QueryBuilder;

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

    public function getStalePackages()
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAll(
            'SELECT p.id FROM package p
            WHERE p.crawledAt IS NULL
            OR (p.autoUpdated = 0 AND p.crawledAt < :crawled)
            OR (p.crawledAt < :autocrawled)
            ORDER BY p.id ASC',
            array(
                'crawled' => date('Y-m-d H:i:s', strtotime('-4hours')),
                'autocrawled' => date('Y-m-d H:i:s', strtotime('-1week')),
            )
        );
    }

    public function getStalePackagesForIndexing()
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAll('SELECT p.id FROM package p WHERE p.indexedAt IS NULL OR p.indexedAt <= p.crawledAt ORDER BY p.id ASC');
    }

    public function getStalePackagesForDumping()
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAll('SELECT p.id FROM package p WHERE p.dumpedAt IS NULL OR p.dumpedAt <= p.crawledAt  ORDER BY p.id ASC');
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
            ->addSelect('a', 'req', 'devReq', 'sug', 'rep', 'con', 'pro')
            ->leftJoin('v.authors', 'a')
            ->leftJoin('v.require', 'req')
            ->leftJoin('v.devRequire', 'devReq')
            ->leftJoin('v.suggest', 'sug')
            ->leftJoin('v.replace', 'rep')
            ->leftJoin('v.conflict', 'con')
            ->leftJoin('v.provide', 'pro')
            ->where('p.name = ?0')
            ->setParameters(array($name));
        return $qb->getQuery()->getSingleResult();
    }

    public function getFullPackages(array $ids = null, $filters = array())
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p', 'v', 't', 'a', 'req', 'devReq', 'sug', 'rep', 'con', 'pro')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->leftJoin('p.versions', 'v')
            ->leftJoin('v.tags', 't')
            ->leftJoin('v.authors', 'a')
            ->leftJoin('v.require', 'req')
            ->leftJoin('v.devRequire', 'devReq')
            ->leftJoin('v.suggest', 'sug')
            ->leftJoin('v.replace', 'rep')
            ->leftJoin('v.conflict', 'con')
            ->leftJoin('v.provide', 'pro')
            ->orderBy('v.development', 'DESC')
            ->addOrderBy('v.releasedAt', 'DESC');

        if (null !== $ids) {
            $qb->where($qb->expr()->in('p.id', ':ids'))
                ->setParameter('ids', $ids);
        }

        $this->addFilters($qb, $filters);

        return $qb->getQuery()->getResult();
    }

    public function getFilteredQueryBuilder(array $filters = array())
    {
        $qb = $this->getBaseQueryBuilder()
            ->select('p', 'v');

        $this->addFilters($qb, $filters);

        return $qb;
    }

    private function addFilters(QueryBuilder $qb, array $filters)
    {
        foreach ($filters as $name => $value) {
            if (null === $value) {
                continue;
            }

            switch ($name) {
                case 'tag':
                    $qb->andWhere($qb->expr()->in('t.name', ':'.$name));
                    break;

                case 'maintainer':
                    $qb->andWhere($qb->expr()->in('m.id', ':'.$name));
                    break;

                default:
                    $qb->andWhere($qb->expr()->in('p.'.$name, ':'.$name));
                    break;
            }

            $qb->setParameter($name, $value);
        }
    }

    public function getBaseQueryBuilder()
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p', 'v', 't', 'm')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->leftJoin('p.versions', 'v')
            ->leftJoin('p.maintainers', 'm')
            ->leftJoin('v.tags', 't')
            ->orderBy('v.development', 'DESC')
            ->addOrderBy('v.releasedAt', 'DESC');

        return $qb;
    }
}
