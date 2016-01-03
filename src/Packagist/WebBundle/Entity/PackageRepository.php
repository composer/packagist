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
        $apc = extension_loaded('apcu');

        if ($apc) {
            $names = apcu_fetch('packagist_package_names');
        }

        if (!is_array($names)) {
            $query = $this->getEntityManager()
                ->createQuery("SELECT p.name FROM Packagist\WebBundle\Entity\Package p");

            $names = $this->getPackageNamesForQuery($query);
            $names = array_combine($names, array_map('strtolower', $names));
            if ($apc) {
                apcu_store('packagist_package_names', $names, 3600);
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
        $apc = extension_loaded('apcu');

        // TODO use container to set caching key and ttl
        if ($apc) {
            $names = apcu_fetch('packagist_provided_names');
        }

        if (!is_array($names)) {
            $query = $this->getEntityManager()
                ->createQuery("SELECT p.packageName AS name FROM Packagist\WebBundle\Entity\ProvideLink p GROUP BY p.packageName");

            $names = $this->getPackageNamesForQuery($query);
            $names = array_combine($names, array_map('strtolower', $names));
            if ($apc) {
                apcu_store('packagist_provided_names', $names, 3600);
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
            ->orderBy('p.name')
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
            WHERE p.abandoned = false
            AND (
                p.crawledAt IS NULL
                OR (p.autoUpdated = 0 AND p.crawledAt < :crawled)
                OR (p.crawledAt < :autocrawled)
            )
            ORDER BY p.id ASC',
            array(
                'crawled' => date('Y-m-d H:i:s', strtotime('-1week')),
                'autocrawled' => date('Y-m-d H:i:s', strtotime('-1month')),
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

    public function getGitHubStars(array $ids)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p.gitHubStars', 'p.id')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->where($qb->expr()->in('p.id', ':ids'))
            ->setParameter('ids', $ids);

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

    public function isVendorTaken($vendor, User $user)
    {
        $query = $this->getEntityManager()
            ->createQuery(
                "SELECT p.name, m.id user_id
                FROM Packagist\WebBundle\Entity\Package p
                JOIN p.maintainers m
                WHERE p.name LIKE :vendor")
            ->setParameters(array('vendor' => $vendor.'/%'));

        $rows = $query->getArrayResult();
        if (!$rows) {
            return false;
        }

        foreach ($rows as $row) {
            if ($row['user_id'] === $user->getId()) {
                return false;
            }
        }

        return true;
    }

    public function getDependentCount($name)
    {
        $apc = extension_loaded('apcu');

        if ($apc) {
            $count = apcu_fetch('packagist_dependentsCount_'.$name);
        }

        if (!isset($count) || !is_numeric($count)) {
            $count = $this->getEntityManager()->getConnection()->fetchColumn(
                "SELECT COUNT(DISTINCT v.package_id)
                FROM package_version v
                LEFT JOIN link_require r ON v.id = r.version_id AND r.packageName = :name
                LEFT JOIN link_require_dev rd ON v.id = rd.version_id AND rd.packageName = :name
                WHERE v.development AND (r.packageName IS NOT NULL OR rd.packageName IS NOT NULL)",
                ['name' => $name]
            );

            if ($apc) {
                apcu_store('packagist_dependentsCount_'.$name, $count, 7*86400);
            }
        }

        return (int) $count;
    }

    public function getDependents($name, $offset = 0, $limit = 15)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p.id, p.name, p.description, p.language, p.abandoned, p.replacementPackage')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->join('p.versions', 'v')
            ->leftJoin('v.devRequire', 'dr')
            ->leftJoin('v.require', 'r')
            ->where('v.development = true')
            ->andWhere('(r.packageName = :name OR dr.packageName = :name)')
            ->groupBy('p.id, p.name, p.description, p.language, p.abandoned, p.replacementPackage')
            ->orderBy('p.name')
            ->setParameter('name', $name);

        return $qb->getQuery()
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->useResultCache(true, 7*86400, 'dependents_'.$name.'_'.$offset.'_'.$limit)
            ->getResult();
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
