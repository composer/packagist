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

    /**
     * Lists all provided names array(name => true)
     *
     * @var array
     */
    private $providedNames;

    public function packageExists($name)
    {
        $packages = $this->getRawPackageNames();

        return isset($packages[$name]) || in_array(strtolower($name), $packages, true);
    }

    public function packageIsProvided($name)
    {
        $packages = $this->getProvidedNames();

        return isset($packages[$name]) || in_array(strtolower($name), $packages, true);
    }

    public function getPackageNames($fields = array())
    {
        return array_keys($this->getRawPackageNames());
    }

    public function getRawPackageNames()
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
            $query = $this->getEntityManager()
                ->createQuery("SELECT p.name FROM Packagist\WebBundle\Entity\Package p");

            $names = $this->getPackageNamesForQuery($query);
            $names = array_combine($names, array_map('strtolower', $names));
            if ($apc) {
                apc_store('packagist_package_names', $names, 3600);
            }
        }

        return $this->packageNames = $names;
    }

    public function getProvidedNames()
    {
        if (null !== $this->providedNames) {
            return $this->providedNames;
        }

        $names = null;
        $apc = extension_loaded('apc');

        // TODO use container to set caching key and ttl
        if ($apc) {
            $names = apc_fetch('packagist_provided_names');
        }

        if (!is_array($names)) {
            $query = $this->getEntityManager()
                ->createQuery("SELECT p.packageName AS name FROM Packagist\WebBundle\Entity\ProvideLink p GROUP BY p.packageName");

            $names = $this->getPackageNamesForQuery($query);
            $names = array_combine($names, array_map('strtolower', $names));
            if ($apc) {
                apc_store('packagist_provided_names', $names, 3600);
            }
        }

        return $this->providedNames = $names;
    }

    public function findProviders($name)
    {
        $query = $this->createQueryBuilder('p')
            ->select('p')
            ->leftJoin('p.versions', 'pv')
            ->leftJoin('pv.provide', 'pr')
            ->where('pv.development = true')
            ->andWhere('pr.packageName = :name')
            ->groupBy('p.name')
            ->getQuery()
            ->setParameters(array('name' => $name));

        return $query->getResult();
    }

    public function getPackageNamesByType($type)
    {
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.name FROM Packagist\WebBundle\Entity\Package p WHERE p.type = :type")
            ->setParameters(array('type' => $type));

        return $this->getPackageNamesForQuery($query);
    }

    public function getPackageNamesByVendor($vendor)
    {
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.name FROM Packagist\WebBundle\Entity\Package p WHERE p.name LIKE :vendor")
            ->setParameters(array('vendor' => $vendor.'/%'));

        return $this->getPackageNamesForQuery($query);
    }

    public function getPackagesWithFields($filters, $fields)
    {
        $selector = '';
        foreach ($fields as $field) {
            $selector .= ', p.'.$field;
        }
        $where = '';
        foreach ($filters as $filter => $val) {
            $where .= 'p.'.$filter.' = :'.$filter;
        }
        if ($where) {
            $where = 'WHERE '.$where;
        }
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.name $selector  FROM Packagist\WebBundle\Entity\Package p $where")
            ->setParameters($filters);

        $result = array();
        foreach ($query->getScalarResult() as $row) {
            $name = $row['name'];
            unset($row['name']);
            $result[$name] = $row;
        }

        return $result;
    }

    private function getPackageNamesForQuery($query)
    {
        $names = array();
        foreach ($query->getScalarResult() as $row) {
            $names[] = $row['name'];
        }

        if (defined('SORT_FLAG_CASE')) {
            sort($names, SORT_STRING | SORT_FLAG_CASE);
        } else {
            sort($names, SORT_STRING);
        }

        return $names;
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

    public function getPackageByName($name)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p', 'm')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->leftJoin('p.maintainers', 'm')
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

    public function getPackagesWithVersions(array $ids = null, $filters = array())
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p', 'v')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->leftJoin('p.versions', 'v')
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
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p')
            ->from('Packagist\WebBundle\Entity\Package', 'p');

        if (isset($filters['tag'])) {
            $qb->leftJoin('p.versions', 'v');
            $qb->leftJoin('v.tags', 't');
        }

        $qb->orderBy('p.id', 'DESC');

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
                    $qb->leftJoin('p.maintainers', 'm');
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

    /**
     * Gets the most recent packages created
     *
     * @return QueryBuilder
     */
    public function getQueryBuilderForNewestPackages()
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->orderBy('p.id', 'DESC');

        return $qb;
    }
}
