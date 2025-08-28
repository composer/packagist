<?php declare(strict_types=1);

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

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 *
 * @extends ServiceEntityRepository<Package>
 */
class PackageRepository extends ServiceEntityRepository
{
    private const LISTING_FIELDS = 'id, name, description, type, gitHubStars, frozen, language, abandoned, replacementPackage';
    // @phpstan-ignore classConstant.unused
    private const LISTING_WITH_AUTO_UPDATE_WARNINGS_FIELDS = 'id, name, description, type, gitHubStars, frozen, language, abandoned, replacementPackage, autoUpdated, repository';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Package::class);
    }

    /**
     * @return array<Package>
     */
    public function findProviders(string $name): array
    {
        $query = $this->createQueryBuilder('p')
            ->select('partial p.{'.self::LISTING_FIELDS.'}')
            ->leftJoin('p.versions', 'pv')
            ->leftJoin('pv.provide', 'pr')
            ->where('pv.development = true')
            ->andWhere('pr.packageName = :name')
            ->orderBy('p.name')
            ->getQuery()
            ->setParameters(['name' => $name]);

        return $query->getResult();
    }

    /**
     * @return array<string>
     */
    public function getPackageNamesUpdatedSince(\DateTimeInterface $date): array
    {
        $query = $this->getEntityManager()
            ->createQuery("
                SELECT p.name FROM App\Entity\Package p
                WHERE p.dumpedAt >= :date AND p.frozen IS NULL
            ")
            ->setParameters(['date' => $date]);

        $names = $this->getPackageNamesForQuery($query);

        return array_map('strtolower', $names);
    }

    /**
     * @return array<string>
     */
    public function getPackageNames(): array
    {
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.name FROM App\Entity\Package p WHERE p.frozen IS NULL OR p.frozen != 'spam'");

        $names = $this->getPackageNamesForQuery($query);

        return array_map('strtolower', $names);
    }

    /**
     * @return array<string>
     */
    public function getProvidedNames(): array
    {
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.packageName AS name
                FROM App\Entity\ProvideLink p
                LEFT JOIN p.version v
                WHERE v.development = true
                GROUP BY p.packageName");

        $names = $this->getPackageNamesForQuery($query);

        return array_map('strtolower', $names);
    }

    /**
     * @return array<string>
     */
    public function getPackageNamesByTypeAndVendor(?string $type, ?string $vendor): array
    {
        $qb = $this->getEntityManager()->getRepository(Package::class)->createQueryBuilder('p')
            ->select('p.name')
            ->where('p.frozen IS NULL');
        if ($type !== null) {
            $qb->andWhere('p.type = :type')
                ->setParameter('type', $type);
        }
        if ($vendor !== null) {
            $qb->andWhere('p.vendor = :vendor')
                ->setParameter('vendor', $vendor);
        }

        return $this->getPackageNamesForQuery($qb->getQuery());
    }

    /**
     * @return array<Package>
     */
    public function getGitHubPackagesByMaintainer(int $userId): array
    {
        $query = $this->createQueryBuilder('p')
            ->select('p')
            ->leftJoin('p.maintainers', 'm')
            ->where('m.id = :userId')
            ->andWhere('p.repository LIKE :repoUrl')
            ->orderBy('p.autoUpdated', 'ASC')
            ->getQuery()
            ->setParameters(['userId' => $userId, 'repoUrl' => 'https://github.com/%']);

        return $query->getResult();
    }

    public function isPackageMaintainedBy(Package $package, int $userId): bool
    {
        $query = $this->createQueryBuilder('p')
            ->select('p.id')
            ->join('p.maintainers', 'm')
            ->where('m.id = :userId')
            ->andWhere('p.id = :package')
            ->getQuery()
            ->setParameters(['userId' => $userId, 'package' => $package]);

        return (bool) $query->getOneOrNullResult();
    }

    /**
     * @param array<string, string|int|bool> $filters
     * @param array<string>                  $fields
     *
     * @return array<string, array<string, string|int|bool|null>>
     */
    public function getPackagesWithFields(array $filters, array $fields): array
    {
        $selector = '';
        foreach ($fields as $field) {
            $selector .= ', p.'.$field;
        }

        if (\in_array('abandoned', $fields, true)) {
            $selector .= ', p.replacementPackage';
        }

        $where = 'p.frozen IS NULL';
        foreach ($filters as $filter => $val) {
            $where .= ' AND p.'.$filter.' = :'.$filter;
        }
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.name $selector FROM App\Entity\Package p WHERE $where ORDER BY p.name")
            ->setParameters($filters);

        $result = [];
        /** @var array{name: string, abandoned?: string, replacementPackage?: string|null} $row */
        foreach ($query->getScalarResult() as $row) {
            $name = $row['name'];
            unset($row['name']);
            if (isset($row['abandoned']) && \array_key_exists('replacementPackage', $row)) {
                $row['abandoned'] = $row['abandoned'] == '1' ? ($row['replacementPackage'] ?? true) : false;
            }
            unset($row['replacementPackage']);
            $result[$name] = $row;
        }

        return $result;
    }

    /**
     * @param Query<mixed, array{name: string}> $query
     *
     * @return list<string>
     */
    private function getPackageNamesForQuery(Query $query): array
    {
        $names = [];
        foreach ($query->getScalarResult() as $row) {
            if (!\is_array($row) || !isset($row['name']) || !\is_string($row['name'])) {
                throw new \LogicException('Excepted rows with a name field, got '.json_encode($row));
            }
            $names[] = $row['name'];
        }

        sort($names, \SORT_STRING | \SORT_FLAG_CASE);

        return $names;
    }

    /**
     * @return list<array{id: int}>
     */
    public function getStalePackages(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative(
            'SELECT p.id FROM package p
            WHERE p.abandoned = false
            AND p.frozen IS NULL
            AND (
                p.crawledAt IS NULL
                OR (p.autoUpdated = 0 AND p.crawledAt < :recent AND p.createdAt >= :yesterday)
                OR (p.autoUpdated = 0 AND p.crawledAt < :crawled)
                OR (p.crawledAt < :autocrawled)
            )
            ORDER BY p.id ASC',
            [
                // crawl new packages every 3h for the first day so that dummy packages get deleted ASAP
                'recent' => date('Y-m-d H:i:s', strtotime('-3hour')),
                'yesterday' => date('Y-m-d H:i:s', strtotime('-1day')),
                // crawl packages without auto-update once every 2week
                'crawled' => date('Y-m-d H:i:s', strtotime('-2week')),
                // crawl all packages including auto-updated once a month just in case
                'autocrawled' => date('Y-m-d H:i:s', strtotime('-1month')),
            ]
        );
    }

    /**
     * @return list<array{id: int}>
     */
    public function getStalePackagesForIndexing(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative('SELECT p.id FROM package p WHERE p.indexedAt IS NULL OR p.indexedAt <= p.crawledAt ORDER BY p.id ASC');
    }

    /**
     * @return list<int>
     */
    public function getStalePackagesForDumping(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchFirstColumn('
            SELECT p.id
            FROM package p
            LEFT JOIN download d ON (d.id = p.id AND d.type = 1)
            WHERE (p.dumpedAt IS NULL OR (p.dumpedAt <= p.crawledAt AND p.crawledAt < NOW()))
            AND p.frozen IS NULL
            AND (d.total > 1000 OR d.lastUpdated > :date)
            ORDER BY p.crawledAt ASC
        ', ['date' => date('Y-m-d H:i:s', strtotime('-4months'))]);
    }

    public function isPackageDumpableForV1(Package $package): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        return (bool) $conn->fetchOne('
            SELECT p.id
            FROM package p
            LEFT JOIN download d ON (d.id = p.id AND d.type = 1)
            WHERE p.id = :id AND p.frozen IS NULL
            AND (d.total > 1000 OR d.lastUpdated > :date)
        ', ['id' => $package->getId(), 'date' => date('Y-m-d H:i:s', strtotime('-4months'))]);
    }

    /**
     * @return list<int>
     */
    public function getStalePackagesForDumpingV2(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchFirstColumn('SELECT p.id FROM package p USE INDEX (dumped2_crawled_frozen_idx) WHERE (p.dumpedAtV2 IS NULL OR (p.dumpedAtV2 <= p.crawledAt AND p.crawledAt < NOW())) AND p.frozen IS NULL');
    }

    /**
     * @return iterable<array{id: int, lastUpdated: \DateTimeImmutable}>
     */
    public function iterateStaleDownloadCountPackageIds(): iterable
    {
        $qb = $this->createQueryBuilder('p');
        $res = $qb
            ->select('p.id, d.lastUpdated, p.createdAt')
            ->leftJoin('p.downloads', 'd')
            ->where('((d.type = :type AND d.lastUpdated < :time) OR d.lastUpdated IS NULL)')
            ->setParameter('type', Download::TYPE_PACKAGE)
            ->setParameter('time', new \DateTimeImmutable('-20hours'))
            ->getQuery()
            ->getResult();

        foreach ($res as $row) {
            yield ['id' => (int) $row['id'], 'lastUpdated' => null === $row['lastUpdated'] ? new \DateTimeImmutable($row['createdAt']->format('r')) : new \DateTimeImmutable($row['lastUpdated']->format('r'))];
        }
    }

    public function getPartialPackageByNameWithVersions(string $name): Package
    {
        // first fetch the package alone to avoid joins with heavy data (description, readme) that would be duplicated for each joined row
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p')
            ->from('App\Entity\Package', 'p')
            ->where('p.name = :name')
            ->setParameter('name', $name);
        $pkg = $qb->getQuery()->getSingleResult();

        // then fetch partial version data here to avoid fetching tons of data,
        // this helps for packages like https://packagist.org/packages/ccxt/ccxt
        // with huge amounts of versions
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('v.id, v.version, v.normalizedVersion, v.development, v.releasedAt, v.extra, v.isDefaultBranch')
            ->from('App\Entity\Version', 'v')
            ->orderBy('v.development', 'DESC')
            ->addOrderBy('v.releasedAt', 'DESC')
            ->where('v.package = :package')
            ->setParameter('package', $pkg);

        $versions = [];
        $reflId = new \ReflectionProperty(Version::class, 'id');
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $versions[] = $v = new Version();
            $reflId->setValue($v, $row['id']);
            $v->setName($pkg->getName());
            $v->setPackage($pkg);
            $v->setVersion($row['version']);
            $v->setNormalizedVersion($row['normalizedVersion']);
            $v->setDevelopment($row['development']);
            $v->setReleasedAt($row['releasedAt']);
            $v->setExtra($row['extra']);
            $v->setIsDefaultBranch($row['isDefaultBranch']);
        }
        $versions = new ArrayCollection($versions);

        $prop = new \ReflectionProperty($pkg, 'versions');
        $prop->setValue($pkg, $versions);

        return $pkg;
    }

    public function getPackageByName(string $name): Package
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p', 'm')
            ->from(Package::class, 'p')
            ->leftJoin('p.maintainers', 'm')
            ->where('p.name = :name')
            ->setParameter('name', $name);

        return $qb->getQuery()->getSingleResult();
    }

    /**
     * @param list<int>|null                 $ids
     * @param array<string, string|int|null> $filters
     *
     * @return Package[]
     */
    public function getPackagesWithVersions(?array $ids = null, array $filters = []): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p', 'v')
            ->from(Package::class, 'p')
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

    /**
     * @param int[] $ids
     *
     * @return array<array{gitHubStars: int|null, id: int}>
     */
    public function getGitHubStars(array $ids): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p.gitHubStars', 'p.id')
            ->from(Package::class, 'p')
            ->where($qb->expr()->in('p.id', ':ids'))
            ->setParameter('ids', $ids);

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array<string, string|int|null> $filters
     */
    public function getFilteredQueryBuilder(array $filters = [], bool $orderByName = false): QueryBuilder
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p')
            ->from(Package::class, 'p');

        if (isset($filters['tag'])) {
            $qb->leftJoin('p.versions', 'v');
            $qb->leftJoin('v.tags', 't');
        }

        $qb->andWhere('(p.frozen IS NULL OR p.frozen != \'spam\')');

        $qb->orderBy('p.abandoned');
        if (true === $orderByName) {
            $qb->addOrderBy('p.name');
        } else {
            $qb->addOrderBy('p.id', 'DESC');
        }

        $this->addFilters($qb, $filters);

        return $qb;
    }

    public function isVendorTaken(string $vendor, ?User $user = null): bool
    {
        $query = $this->getEntityManager()
            ->createQuery(
                "SELECT p.name, m.id user_id
                FROM App\Entity\Package p
                JOIN p.maintainers m
                WHERE p.vendor = :vendor"
            )
            ->setParameters(['vendor' => $vendor]);

        $rows = $query->getArrayResult();
        if (!$rows) {
            return false;
        }

        if ($user instanceof User) {
            foreach ($rows as $row) {
                if ($row['user_id'] === $user->getId()) {
                    return false;
                }
            }
        }

        return true;
    }

    public function markPackageSuspect(Package $package): void
    {
        $sql = 'UPDATE package SET suspect = :suspect WHERE id = :id';
        $this->getEntityManager()->getConnection()->executeStatement($sql, ['suspect' => $package->getSuspect(), 'id' => $package->getId()]);
    }

    /**
     * @return int<0, max>
     */
    public function getSuspectPackageCount(): int
    {
        $sql = 'SELECT COUNT(*) count FROM package p WHERE p.suspect IS NOT NULL AND p.frozen IS NULL';

        return max(0, (int) $this->getEntityManager()->getConnection()->fetchOne($sql));
    }

    /**
     * @return array<array{id: int, name: string, description: string|null, language: string|null, abandoned: int, replacementPackage: string|null}>
     */
    public function getSuspectPackages(int $offset = 0, int $limit = 15): array
    {
        $sql = 'SELECT p.id, p.name, p.description, p.language, p.abandoned, p.replacementPackage
            FROM package p WHERE p.suspect IS NOT NULL AND p.frozen IS NULL ORDER BY p.createdAt DESC LIMIT '.((int) $limit).' OFFSET '.((int) $offset);

        return $this->getEntityManager()->getConnection()->fetchAllAssociative($sql);
    }

    /**
     * @param string   $name Package name to find the dependents of
     * @param int|null $type One of Dependent::TYPE_*
     *
     * @return int<0, max>
     */
    public function getDependentCount(string $name, ?int $type = null): int
    {
        $sql = 'SELECT COUNT(*) count FROM dependent WHERE packageName = :name';
        $args = ['name' => $name];
        if (null !== $type) {
            $sql .= ' AND type = :type';
            $args['type'] = $type;
        }

        return max(0, (int) $this->getEntityManager()->getConnection()->fetchOne($sql, $args));
    }

    /**
     * @param string             $name    Package name to find the dependents of
     * @param int|null           $type    One of Dependent::TYPE_*
     * @param 'downloads'|'name' $orderBy
     *
     * @return list<array{id: int, name: string, description: string|null, language: string|null, abandoned: int, replacementPackage: string|null}>
     */
    public function getDependents(string $name, int $offset = 0, int $limit = 15, string $orderBy = 'name', ?int $type = null): array
    {
        $orderByField = 'p.name ASC';
        $join = '';
        if ($orderBy === 'downloads') {
            $orderByField = 'd.total DESC';
            $join = 'LEFT JOIN download d ON d.id = p.id AND d.type = '.Download::TYPE_PACKAGE;
        } else {
            $orderBy = 'name';
        }

        $args = ['name' => $name];
        $typeFilter = '';
        if (null !== $type) {
            $typeFilter = ' AND type = :type';
            $args['type'] = $type;
        }

        $sql = 'SELECT p.id, p.name, p.description, p.language, p.abandoned, p.replacementPackage
            FROM package p INNER JOIN (
                SELECT DISTINCT package_id FROM dependent WHERE packageName = :name'.$typeFilter.'
            ) x ON x.package_id = p.id '.$join.' ORDER BY '.$orderByField.' LIMIT '.((int) $limit).' OFFSET '.((int) $offset);

        $res = [];
        /** @var array{id: int, name: string, description: string|null, language: string|null, abandoned: bool, replacementPackage: string|null} $row */
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $args) as $row) {
            $res[] = ['id' => (int) $row['id'], 'abandoned' => (int) $row['abandoned']] + $row;
        }

        return $res;
    }

    /**
     * @param list<string> $requirers
     * @return array<string, string|null> array keyed by requirer name and the value is requirement or null if not found
     */
    public function getDefaultBranchRequireFor(array $requirers, string $requiree): array
    {
        $requires = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
            SELECT p.name, COALESCE(lr.packageVersion, lrd.packageVersion, NULL) AS requirement
            FROM package p
            LEFT JOIN package_version pv ON pv.package_id = p.id AND pv.defaultBranch = 1
            LEFT JOIN link_require lr ON lr.version_id = pv.id AND lr.packageName = :requiree
            LEFT JOIN link_require_dev lrd ON lrd.version_id = pv.id AND lrd.packageName = :requiree
            WHERE p.name IN (:requirers)
            SQL,
            ['requiree' => $requiree, 'requirers' => $requirers],
            ['requirers' => ArrayParameterType::STRING],
        );

        $result = [];
        foreach ($requires as $row) {
            $result[$row['name']] = $row['requirement'];
        }

        return $result;
    }

    /**
     * @return int<0, max>
     */
    public function getSuggestCount(string $name): int
    {
        $sql = 'SELECT COUNT(*) count FROM suggester WHERE packageName = :name';
        $args = ['name' => $name];

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, $args);
    }

    /**
     * @return array<array{id: int, name: string, description: string|null, language: string|null, abandoned: int, replacementPackage: string|null}>
     */
    public function getSuggests(string $name, int $offset = 0, int $limit = 15): array
    {
        $sql = 'SELECT p.id, p.name, p.description, p.language, p.abandoned, p.replacementPackage
            FROM package p INNER JOIN (
                SELECT DISTINCT package_id FROM suggester WHERE packageName = :name
            ) x ON x.package_id = p.id ORDER BY p.name ASC LIMIT '.((int) $limit).' OFFSET '.((int) $offset);

        $res = [];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative($sql, ['name' => $name]) as $row) {
            $res[] = ['id' => (int) $row['id'], 'abandoned' => (int) $row['abandoned']] + $row;
        }

        return $res;
    }

    public function getTotal(): int
    {
        // it seems the GROUP BY 1=1 helps mysql figure out a faster way to get the count by using another index
        $sql = 'SELECT COUNT(*) count FROM `package` GROUP BY 1=1';

        $stmt = $this->getEntityManager()->getConnection()
            ->executeCacheQuery(
                $sql,
                [],
                [],
                new QueryCacheProfile(86400, 'total_packages', $this->getEntityManager()->getConfiguration()->getResultCache())
            );
        $result = $stmt->fetchAllAssociative();
        $stmt->free();

        return (int) $result[0]['count'];
    }

    /**
     * @return array<int<0, max>, array{count: int, year: int|null, month: int|null}>
     */
    public function getCountByYearMonth(): array
    {
        $sql = 'SELECT COUNT(*) count, YEAR(createdAt) year, MONTH(createdAt) month FROM `package` GROUP BY year, month';

        $stmt = $this->getEntityManager()->getConnection()
            ->executeCacheQuery(
                $sql,
                [],
                [],
                new QueryCacheProfile(3600, 'package_count_by_year_month', $this->getEntityManager()->getConfiguration()->getResultCache())
            );
        $result = $stmt->fetchAllAssociative();
        $stmt->free();

        return $result;
    }

    /**
     * @param array<string, string|int|null> $filters
     */
    private function addFilters(QueryBuilder $qb, array $filters): void
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
                    $qb->andWhere('p.vendor = :vendor');
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
     */
    public function getQueryBuilderForNewestPackages(): QueryBuilder
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p')
            ->from('App\Entity\Package', 'p')
            ->where('p.abandoned = false')
            ->orderBy('p.id', 'DESC');

        return $qb;
    }
}
