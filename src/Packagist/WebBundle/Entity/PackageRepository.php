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
use Doctrine\DBAL\Cache\QueryCacheProfile;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackageRepository extends EntityRepository
{
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

    public function getPackageNames()
    {
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.name FROM Packagist\WebBundle\Entity\Package p");

        $names = $this->getPackageNamesForQuery($query);

        return array_map('strtolower', $names);
    }

    public function getProvidedNames()
    {
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.packageName AS name
                FROM Packagist\WebBundle\Entity\ProvideLink p
                LEFT JOIN p.version v
                WHERE v.development = true
                GROUP BY p.packageName");

        $names = $this->getPackageNamesForQuery($query);

        return array_map('strtolower', $names);
    }

    public function getPackageNamesByType($type)
    {
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.name FROM Packagist\WebBundle\Entity\Package p WHERE p.type = :type AND (p.replacementPackage IS NULL OR p.replacementPackage != 'spam/spam')")
            ->setParameters(array('type' => $type));

        return $this->getPackageNamesForQuery($query);
    }

    public function getPackageNamesByVendor($vendor)
    {
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.name FROM Packagist\WebBundle\Entity\Package p WHERE p.name LIKE :vendor AND (p.replacementPackage IS NULL OR p.replacementPackage != 'spam/spam')")
            ->setParameters(array('vendor' => $vendor.'/%'));

        return $this->getPackageNamesForQuery($query);
    }

    public function getPackagesWithFields($filters, $fields)
    {
        $selector = '';
        foreach ($fields as $field) {
            $selector .= ', p.'.$field;
        }
        $where = 'p.replacementPackage != :replacement';
        foreach ($filters as $filter => $val) {
            $where .= ' AND p.'.$filter.' = :'.$filter;
        }
        $filters['replacement'] = "spam/spam";
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.name $selector  FROM Packagist\WebBundle\Entity\Package p WHERE $where")
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
                OR (p.autoUpdated = 0 AND p.crawledAt < :recent AND p.createdAt >= :yesterday)
                OR (p.autoUpdated = 0 AND p.crawledAt < :crawled)
                OR (p.crawledAt < :autocrawled)
            )
            ORDER BY p.id ASC',
            array(
                // crawl new packages once an hour for the first day so that dummy packages get deleted ASAP
                'recent' => date('Y-m-d H:i:s', strtotime('-1hour')),
                'yesterday' => date('Y-m-d H:i:s', strtotime('-1day')),
                // crawl packages without auto-update once a week
                'crawled' => date('Y-m-d H:i:s', strtotime('-1week')),
                // crawl auto-updated packages once a month just in case
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

        return $conn->fetchAll('SELECT p.id FROM package p WHERE p.dumpedAt IS NULL OR p.dumpedAt <= p.crawledAt AND p.crawledAt < NOW() ORDER BY p.id ASC');
    }

    public function getPartialPackageByNameWithVersions($name)
    {
        // first fetch a partial package including joined versions/maintainers, that way
        // the join is cheap and heavy data (description, readme) is not duplicated for each joined row
        //
        // fetching everything partial here to avoid fetching tons of data,
        // this helps for packages like https://packagist.org/packages/ccxt/ccxt
        // with huge amounts of versions
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('partial p.{id}', 'partial v.{id, version, normalizedVersion, development, releasedAt}', 'partial m.{id, username, email}')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->leftJoin('p.versions', 'v')
            ->leftJoin('p.maintainers', 'm')
            ->orderBy('v.development', 'DESC')
            ->addOrderBy('v.releasedAt', 'DESC')
            ->where('p.name = ?0')
            ->setParameters(array($name));

        $pkg = $qb->getQuery()->getSingleResult();

        if ($pkg) {
            // then refresh the package to complete its data and inject the previously fetched versions/maintainers to
            // get a complete package
            $versions = $pkg->getVersions();
            $maintainers = $pkg->getMaintainers();
            $this->getEntityManager()->refresh($pkg);

            $prop = new \ReflectionProperty($pkg, 'versions');
            $prop->setAccessible(true);
            $prop->setValue($pkg, $versions);

            $prop = new \ReflectionProperty($pkg, 'maintainers');
            $prop->setAccessible(true);
            $prop->setValue($pkg, $maintainers);
        }

        return $pkg;
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

    public function getFilteredQueryBuilder(array $filters = array(), $orderByName = false)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p')
            ->from('Packagist\WebBundle\Entity\Package', 'p');

        if (isset($filters['tag'])) {
            $qb->leftJoin('p.versions', 'v');
            $qb->leftJoin('v.tags', 't');
        }

        $qb->orderBy('p.abandoned');
        if (true === $orderByName) {
            $qb->addOrderBy('p.name');
        } else {
            $qb->addOrderBy('p.id', 'DESC');
        }

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
        $sql = 'SELECT COUNT(*) count FROM (
                SELECT pv.package_id FROM link_require r INNER JOIN package_version pv ON (pv.id = r.version_id AND pv.development = 1) WHERE r.packageName = :name
                UNION
                SELECT pv.package_id FROM link_require_dev r INNER JOIN package_version pv ON (pv.id = r.version_id AND pv.development = 1) WHERE r.packageName = :name
            ) x';

        $stmt = $this->getEntityManager()->getConnection()
            ->executeCacheQuery($sql, ['name' => $name], [], new QueryCacheProfile(7*86400, 'dependents_count_'.$name, $this->getEntityManager()->getConfiguration()->getResultCacheImpl()));
        $result = $stmt->fetchAll();
        $stmt->closeCursor();

        return (int) $result[0]['count'];
    }

    public function getDependents($name, $offset = 0, $limit = 15)
    {
        $sql = 'SELECT p.id, p.name, p.description, p.language, p.abandoned, p.replacementPackage
            FROM package p INNER JOIN (
                SELECT pv.package_id FROM link_require r INNER JOIN package_version pv ON (pv.id = r.version_id AND pv.development = 1) WHERE r.packageName = :name
                UNION
                SELECT pv.package_id FROM link_require_dev r INNER JOIN package_version pv ON (pv.id = r.version_id AND pv.development = 1) WHERE r.packageName = :name
            ) x ON x.package_id = p.id ORDER BY p.name ASC LIMIT '.((int)$limit).' OFFSET '.((int)$offset);

        $stmt = $this->getEntityManager()->getConnection()
            ->executeCacheQuery(
                $sql,
                ['name' => $name],
                [],
                new QueryCacheProfile(7*86400, 'dependents_'.$name.'_'.$offset.'_'.$limit, $this->getEntityManager()->getConfiguration()->getResultCacheImpl())
            );
        $result = $stmt->fetchAll();
        $stmt->closeCursor();

        return $result;
    }

    public function getSuggestCount($name)
    {
        $sql = 'SELECT COUNT(DISTINCT pv.package_id) count
            FROM link_suggest s
            INNER JOIN package_version pv ON (pv.id = s.version_id AND pv.development = 1)
            WHERE s.packageName = :name';

        $stmt = $this->getEntityManager()->getConnection()
            ->executeCacheQuery($sql, ['name' => $name], [], new QueryCacheProfile(7*86400, 'suggesters_count_'.$name, $this->getEntityManager()->getConfiguration()->getResultCacheImpl()));
        $result = $stmt->fetchAll();
        $stmt->closeCursor();

        return (int) $result[0]['count'];
    }

    public function getSuggests($name, $offset = 0, $limit = 15)
    {
        $sql = 'SELECT p.id, p.name, p.description, p.language, p.abandoned, p.replacementPackage
            FROM link_suggest s
            INNER JOIN package_version pv ON (pv.id = s.version_id AND pv.development = 1)
            INNER JOIN package p ON (p.id = pv.package_id)
            WHERE s.packageName = :name
            GROUP BY pv.package_id
            ORDER BY p.name ASC LIMIT '.((int)$limit).' OFFSET '.((int)$offset);

        $stmt = $this->getEntityManager()->getConnection()
            ->executeCacheQuery(
                $sql,
                ['name' => $name],
                [],
                new QueryCacheProfile(7*86400, 'suggesters_'.$name.'_'.$offset.'_'.$limit, $this->getEntityManager()->getConfiguration()->getResultCacheImpl())
            );
        $result = $stmt->fetchAll();
        $stmt->closeCursor();

        return $result;
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

                case 'vendor':
                    $qb->andWhere('p.name LIKE :vendor');
                    break;

                default:
                    $qb->andWhere($qb->expr()->in('p.'.$name, ':'.$name));
                    break;
            }

            $qb->setParameter($name, $value);
        }
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
            ->where('p.abandoned = false')
            ->orderBy('p.id', 'DESC');

        return $qb;
    }
}
