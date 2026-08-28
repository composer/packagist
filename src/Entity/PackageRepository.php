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

use Composer\Pcre\Preg;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;
use Predis\PredisException;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 *
 * @extends ServiceEntityRepository<Package>
 */
class PackageRepository extends ServiceEntityRepository
{
    /**
     * Bounds of the "lots of views, no installs" spam heuristic implemented in
     * PackageController::viewPackageAction(), shared so packagist:clean-view-counters can tell which
     * view counters are still live by exactly the same rules.
     */
    public const SUSPECT_VIEWS_MIN_CREATED_AT = '2019-05-01';
    public const SUSPECT_VIEWS_MAX_DOWNLOADS = 10;

    private const LISTING_FIELDS = 'id, name, description, type, gitHubStars, frozen, language, abandoned, replacementPackage';

    /**
     * The index ranges, counts and annotations on the listing path, none of which sorts anything.
     * 3s is far above what any of them should need; it is here to stop a bad plan holding a worker,
     * not as a budget anything is expected to spend.
     */
    private const LISTING_QUERY_TIMEOUT_HINT = '/*+ MAX_EXECUTION_TIME(3000) */';

    /**
     * The download sort filesorts the whole joined set before paginating however shallow the page,
     * which averages ~10ms but has run for nearly 9 minutes in production, and ~3s on the largest
     * packages cold. It gets the larger budget because giving up does not save the work: the cap is
     * spent in full and then the caller pays for the unsorted listing on top. 5s buys the packages
     * that sit just past 3s a correct answer for roughly what the timeout already costs them.
     */
    private const SORTED_LISTING_TIMEOUT_HINT = '/*+ MAX_EXECUTION_TIME(5000) */';
    // @phpstan-ignore classConstant.unused
    private const LISTING_WITH_AUTO_UPDATE_WARNINGS_FIELDS = 'id, name, description, type, gitHubStars, frozen, language, abandoned, replacementPackage, autoUpdated, repository';

    public function __construct(
        ManagerRegistry $registry,
        private Client $redisCache,
    ) {
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

        $result = $query->getResult();

        if (Preg::isMatch('{^ext-(.*)$}', $name, $match)) {
            $query = $this->createQueryBuilder('p')
                ->select('partial p.{'.self::LISTING_FIELDS.'}')
                ->leftJoin('p.versions', 'pv')
                ->where('pv.development = true')
                ->andWhere('(p.type = :extType OR p.type = :extTypeAlt)')
                ->andWhere('(JSON_EXTRACT(pv.phpExt, \'$."extension-name"\') IN (:name, :altName) OR (JSON_EXTRACT(pv.phpExt, \'$."extension-name"\') IS NULL AND p.name LIKE :pkgName))')
                ->orderBy('p.name')
                ->getQuery()
                ->setParameters([
                    'extType' => 'php-ext',
                    'extTypeAlt' => 'php-ext-zend',
                    'name' => $match[1],
                    'altName' => 'ext-'.$match[1],
                    'pkgName' => '%/'.$match[1],
                ]);

            $result = array_merge($result, $query->getResult());
        }

        return $result;
    }

    /**
     * @return array<string>
     */
    public function getPackageNamesUpdatedSince(\DateTimeInterface $date): array
    {
        $query = $this->getEntityManager()
            ->createQuery('
                SELECT p.name FROM App\Entity\Package p
                WHERE p.dumpedAt >= :date AND (p.frozen IS NULL OR p.frozen NOT IN (:suppressed))
            ')
            ->setParameters(['date' => $date, 'suppressed' => PackageFreezeReason::suppressingCases()]);

        $names = $this->getPackageNamesForQuery($query);

        return array_map('strtolower', $names);
    }

    /**
     * @return array<string>
     */
    public function getPackageNames(): array
    {
        $query = $this->getEntityManager()
            ->createQuery('SELECT p.name FROM App\Entity\Package p WHERE p.frozen IS NULL OR p.frozen NOT IN (:suppressed)')
            ->setParameter('suppressed', PackageFreezeReason::suppressingCases());

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
     * Sorts in SQL rather than with PHP's SORT_STRING|SORT_FLAG_CASE like the lookups above, because
     * a stream cannot be sorted after the fact. Over the legal package name charset the two orders
     * differ only in where `_` lands: utf8mb4_unicode_ci puts it before `-`, `.`, `/` and the digits.
     *
     * @return \Generator<int, string>
     */
    public function iteratePackageNamesByTypeAndVendor(?string $type, ?string $vendor): \Generator
    {
        $qb = $this->getEntityManager()->getRepository(Package::class)->createQueryBuilder('p')
            ->select('p.name')
            ->where('(p.frozen IS NULL OR p.frozen NOT IN (:suppressed))')
            ->orderBy('p.name', 'ASC')
            ->setParameter('suppressed', PackageFreezeReason::suppressingCases());
        if ($type !== null) {
            $qb->andWhere('p.type = :type')
                ->setParameter('type', $type);
        }
        if ($vendor !== null) {
            $qb->andWhere('p.vendor = :vendor')
                ->setParameter('vendor', $vendor);
        }

        return $this->iteratePackageNamesForQuery($qb->getQuery());
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
     * All packages the user is a direct maintainer of. Returns package id + vendor + name, ordered by id for deterministic fan-out.
     * In the future this will need to also take into account organization membership.
     *
     * @return list<array{id: int, vendor: string, name: string}>
     */
    public function getPackageRefsByMaintainer(int $userId): array
    {
        /** @var list<array{id: int|string, vendor: string, name: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT p.id AS id, p.vendor AS vendor, p.name AS name
                FROM package p
                JOIN maintainers_packages mp ON mp.package_id = p.id AND mp.user_id = :userId
                ORDER BY p.id ASC',
            ['userId' => $userId],
        );

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'vendor' => (string) $row['vendor'], 'name' => (string) $row['name']],
            $rows,
        );
    }

    public function getPackageIdByName(string $name): ?int
    {
        $id = $this->createQueryBuilder('p')
            ->select('p.id')
            ->where('p.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_SCALAR_COLUMN);

        return $id === null ? null : (int) $id;
    }

    /**
     * @param array<string, string|int|bool> $filters
     * @param array<string>                  $fields
     *
     * @return \Generator<string, array<string, string|int|bool|null>>
     */
    public function iteratePackagesWithFields(array $filters, array $fields): \Generator
    {
        $selector = '';
        foreach ($fields as $field) {
            $selector .= ', p.'.$field;
        }

        if (\in_array('abandoned', $fields, true)) {
            $selector .= ', p.replacementPackage';
        }

        $where = '(p.frozen IS NULL OR p.frozen NOT IN (:suppressedReasons))';
        foreach ($filters as $filter => $val) {
            $where .= ' AND p.'.$filter.' = :'.$filter;
        }
        $query = $this->getEntityManager()
            ->createQuery("SELECT p.name $selector FROM App\Entity\Package p WHERE $where ORDER BY p.name")
            ->setParameters(array_merge($filters, ['suppressedReasons' => PackageFreezeReason::suppressingCases()]));

        // yielded row by row so the caller can stream it out without materialising the result set
        /** @var array{name: string, abandoned?: string, replacementPackage?: string|null} $row */
        foreach ($query->toIterable([], Query::HYDRATE_SCALAR) as $row) {
            $name = $row['name'];
            unset($row['name']);
            if (isset($row['abandoned']) && \array_key_exists('replacementPackage', $row)) {
                $row['abandoned'] = $row['abandoned'] == '1' ? ($row['replacementPackage'] ?? true) : false;
            }
            unset($row['replacementPackage']);

            yield $name => $row;
        }
    }

    /**
     * @param Query<mixed, array{name: string}> $query
     *
     * @return \Generator<int, string>
     */
    private function iteratePackageNamesForQuery(Query $query): \Generator
    {
        foreach ($query->toIterable([], Query::HYDRATE_SCALAR) as $row) {
            if (!\is_array($row) || !isset($row['name']) || !\is_string($row['name'])) {
                throw new \LogicException('Excepted rows with a name field, got '.json_encode($row));
            }

            yield $row['name'];
        }
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
    public function getStalePackagesForUpdating(): array
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
            AND (p.frozen IS NULL OR p.frozen NOT IN (:suppressed))
            AND (d.total > 1000 OR d.lastUpdated > :date)
            ORDER BY p.crawledAt ASC
        ', ['date' => date('Y-m-d H:i:s', strtotime('-4months')), 'suppressed' => PackageFreezeReason::suppressingValues()], ['suppressed' => ArrayParameterType::STRING]);
    }

    /**
     * @return list<int>
     */
    public function getStalePackagesForDumpingV2(int $workerId = 0, int $numWorkers = 1): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = 'SELECT p.id FROM package p USE INDEX (dumped2_crawled_frozen_idx) WHERE (p.dumpedAtV2 IS NULL OR (p.dumpedAtV2 <= p.crawledAt AND p.crawledAt < NOW())) AND (p.frozen IS NULL OR p.frozen NOT IN (:suppressed))';
        $params = ['suppressed' => PackageFreezeReason::suppressingValues()];
        $types = ['suppressed' => ArrayParameterType::STRING];

        if ($numWorkers > 1) {
            $sql .= ' AND p.id % :numWorkers = :workerId';
            $params['numWorkers'] = $numWorkers;
            $params['workerId'] = $workerId;
        }

        return $conn->fetchFirstColumn($sql, $params, $types);
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
     * @param bool $includeFrozen When false (default) suppressed (spam/malware) frozen packages are
     *                            excluded, matching the public listings. Pass true only for
     *                            moderator-facing listings.
     */
    public function getFilteredQueryBuilder(array $filters = [], bool $orderByName = false, bool $includeFrozen = false): QueryBuilder
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p')
            ->from(Package::class, 'p');

        if (isset($filters['tag'])) {
            $qb->leftJoin('p.versions', 'v');
            $qb->leftJoin('v.tags', 't');
        }

        if (!$includeFrozen) {
            $qb->andWhere('(p.frozen IS NULL OR p.frozen NOT IN (:suppressedReasons))')
                ->setParameter('suppressedReasons', PackageFreezeReason::suppressingCases());
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
     * @return array<array{id: int, name: string, description: string|null, type: string|null, language: string|null, abandoned: int, replacementPackage: string|null}>
     */
    public function getSuspectPackages(int $offset = 0, int $limit = 15): array
    {
        $sql = 'SELECT p.id, p.name, p.description, p.type, p.language, p.abandoned, p.replacementPackage
            FROM package p WHERE p.suspect IS NOT NULL AND p.frozen IS NULL ORDER BY p.createdAt DESC LIMIT '.((int) $limit).' OFFSET '.((int) $offset);

        return $this->getEntityManager()->getConnection()->fetchAllAssociative($sql);
    }

    /**
     * Every package currently in the spam review queue, ordered so a vendor's packages are adjacent.
     * Used by the automated triage command (packagist:spam:triage-queue).
     *
     * @return array<array{id: int, name: string, description: string|null, vendor: string}>
     */
    public function getAllSuspectPackages(): array
    {
        $sql = 'SELECT p.id, p.name, p.description, p.vendor
            FROM package p WHERE p.suspect IS NOT NULL AND p.frozen IS NULL ORDER BY p.vendor ASC, p.name ASC';

        return $this->getEntityManager()->getConnection()->fetchAllAssociative($sql);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, list<string>> map of package id => list of tag names (across all versions)
     */
    public function getTagsByPackageIds(array $ids): array
    {
        if (\count($ids) === 0) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT pv.package_id AS pid, GROUP_CONCAT(DISTINCT t.name SEPARATOR \',\') AS tags
             FROM package_version pv
             JOIN version_tag vt ON vt.version_id = pv.id
             JOIN tag t ON t.id = vt.tag_id
             WHERE pv.package_id IN (:ids)
             GROUP BY pv.package_id',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $tagsById = [];
        foreach ($rows as $row) {
            $tagsById[(int) $row['pid']] = array_values(array_filter(explode(',', (string) $row['tags']), static fn (string $t) => $t !== ''));
        }

        return $tagsById;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string> map of package id => stored README HTML (only ids that have one)
     */
    public function getReadmeContentsByPackageIds(array $ids): array
    {
        if (\count($ids) === 0) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT package_id AS pid, contents FROM package_readme WHERE package_id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['pid']] = (string) $row['contents'];
        }

        return $byId;
    }

    /**
     * @param string   $name   Package name to find the dependents of
     * @param int|null $type   One of Dependent::TYPE_*
     * @param bool     $cached Pass false when the count must match a row list queried alongside it
     *
     * @return int<0, max>
     */
    public function getDependentCount(string $name, ?int $type = null, bool $cached = true): int
    {
        // Only the uncached listing path is bounded: there a timeout degrades into the listing's own
        // 503, whereas the cached path feeds a badge on the package page, where it must not 500.
        $hint = $cached ? '' : self::LISTING_QUERY_TIMEOUT_HINT.' ';

        $compute = function () use ($name, $type, $hint): int {
            // DISTINCT because the PK carries type, so one package requiring both in require and
            // require-dev has two rows but is a single entry in the listing this count paginates
            $sql = 'SELECT '.$hint.'COUNT(DISTINCT package_id) count FROM dependent WHERE packageName = :name';
            $args = ['name' => $name];
            if (null !== $type) {
                $sql .= ' AND type = :type';
                $args['type'] = $type;
            }

            return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, $args);
        };

        return $cached
            ? $this->getCachedCount('dep-count:'.strtolower($name).':'.($type ?? 'all'), $compute)
            : max(0, $compute());
    }

    /**
     * @param string                  $name    Package name to find the dependents of
     * @param int|null                $type    One of Dependent::TYPE_*
     * @param 'downloads'|'name'|null $orderBy null skips the sort, for when ordering the whole set
     *                                         exceeds the statement timeout. The download join goes
     *                                         with it, as it only exists to sort on.
     *
     * @return list<array{id: int, name: string, description: string|null, type: string|null, language: string|null, abandoned: int, replacementPackage: string|null}>
     */
    public function getDependents(string $name, int $offset = 0, int $limit = 15, ?string $orderBy = 'name', ?int $type = null): array
    {
        $join = '';
        $orderByClause = '';
        if ($orderBy === 'downloads') {
            $join = 'LEFT JOIN download d ON d.id = p.id AND d.type = '.Download::TYPE_PACKAGE;
            $orderByClause = ' ORDER BY d.total DESC';
        } elseif (null !== $orderBy) {
            $orderByClause = ' ORDER BY p.name ASC';
        }

        $args = ['name' => $name];
        $typeFilter = '';
        if (null !== $type) {
            $typeFilter = ' AND type = :type';
            $args['type'] = $type;
        }

        $sql = 'SELECT '.self::SORTED_LISTING_TIMEOUT_HINT.' p.id, p.name, p.description, p.type, p.language, p.abandoned, p.replacementPackage, p.frozen
            FROM package p INNER JOIN (
                SELECT DISTINCT package_id FROM dependent WHERE packageName = :name'.$typeFilter.'
            ) x ON x.package_id = p.id '.$join.'
            '.$orderByClause.'
            LIMIT '.((int) $limit).' OFFSET '.((int) $offset);

        $suppressed = PackageFreezeReason::suppressingValues();

        $res = [];
        /** @var array{id: int, name: string, description: string|null, type: string|null, language: string|null, abandoned: bool, replacementPackage: string|null, frozen: string|null} $row */
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $args) as $row) {
            // dropped here rather than by a WHERE clause: the OR/NOT IN on a nullable column is not
            // sargable and cost the optimizer its index-ordered plan, taking this query from 141
            // rows examined per call to 28k and spilling the sort to disk. The count does not filter
            // them either, so a page can come back short and the pager run a few entries high.
            if (\in_array($row['frozen'], $suppressed, true)) {
                continue;
            }
            unset($row['frozen']);

            $res[] = ['id' => (int) $row['id'], 'abandoned' => (int) $row['abandoned']] + $row;
        }

        return $res;
    }

    /**
     * The unsorted listing: dependents in package_id order, which is whatever order by_name_package
     * already holds them in, so nothing has to be sorted at any depth.
     *
     * The ordering listings do by p.name or d.total lives on the joined package, so the whole set
     * has to be materialised and filesorted before a page can be taken off it - 105,878 rows and
     * ~3s on illuminate/support, and the same work for page 1 as for page 500. Here the limit is
     * pushed into the index scan instead: the derived table is a range over
     * (packageName, package_id, type), stops at $limit, and only then joins package by primary key.
     *
     * DISTINCT because a package requiring the same name in both require and require-dev has two
     * rows; the index puts them next to each other so collapsing them costs nothing.
     *
     * @param int|null $afterId Seek past this package id, for cursor paging. Mutually exclusive
     *                          with $offset, which is what the numbered html pager uses.
     * @param int|null $type    One of Dependent::TYPE_*
     *
     * @return array{packages: list<array{id: int, name: string, description: string|null, type: string|null, language: string|null, abandoned: int, replacementPackage: string|null}>, cursor: int|null}
     */
    public function getDependentsUnsorted(string $name, ?int $afterId = null, int $offset = 0, int $limit = 100, ?int $type = null): array
    {
        $args = ['name' => $name];
        $filter = '';
        if (null !== $type) {
            $filter .= ' AND type = :type';
            $args['type'] = $type;
        }
        if (null !== $afterId) {
            $filter .= ' AND package_id > :afterId';
            $args['afterId'] = $afterId;
        }

        $sql = 'SELECT '.self::LISTING_QUERY_TIMEOUT_HINT.' p.id, p.name, p.description, p.type, p.language, p.abandoned, p.replacementPackage, p.frozen
            FROM package p INNER JOIN (
                SELECT DISTINCT package_id FROM dependent WHERE packageName = :name'.$filter.'
                ORDER BY package_id ASC
                LIMIT '.((int) $limit).' OFFSET '.((int) $offset).'
            ) x ON x.package_id = p.id
            ORDER BY x.package_id ASC';

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $args);

        $suppressed = PackageFreezeReason::suppressingValues();
        $packages = [];
        $lastFetched = null;
        /** @var array{id: int, name: string, description: string|null, type: string|null, language: string|null, abandoned: bool, replacementPackage: string|null, frozen: string|null} $row */
        foreach ($rows as $row) {
            // the cursor tracks every row fetched, not every row kept: a page made entirely of
            // suppressed packages still has to advance or a client asks for it forever
            $lastFetched = (int) $row['id'];

            // see getDependents() for why these are dropped here rather than in SQL
            if (\in_array($row['frozen'], $suppressed, true)) {
                continue;
            }
            unset($row['frozen']);

            $packages[] = ['id' => (int) $row['id'], 'abandoned' => (int) $row['abandoned']] + $row;
        }

        return [
            'packages' => $packages,
            // a short page is the end of the set, so there is nothing further to ask for
            'cursor' => \count($rows) === $limit ? $lastFetched : null,
        ];
    }

    /**
     * Bounded like the listing it annotates: it runs once per listing page with one name per row,
     * so left unbounded it hands back the worker occupancy the listing's own cap buys. The caller
     * drops the annotation on a timeout rather than failing the page.
     *
     * @param list<string> $requirers
     *
     * @return array<string, string|null> array keyed by requirer name and the value is requirement or null if not found
     */
    public function getDefaultBranchRequireFor(array $requirers, string $requiree): array
    {
        $sql = 'SELECT '.self::LISTING_QUERY_TIMEOUT_HINT.' p.name, COALESCE(lr.packageVersion, lrd.packageVersion, NULL) AS requirement
            FROM package p
            LEFT JOIN package_version pv ON pv.package_id = p.id AND pv.defaultBranch = 1
            LEFT JOIN link_require lr ON lr.version_id = pv.id AND lr.packageName = :requiree
            LEFT JOIN link_require_dev lrd ON lrd.version_id = pv.id AND lrd.packageName = :requiree
            WHERE p.name IN (:requirers)';

        $requires = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
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
     * @param bool $cached Pass false when the count must match a row list queried alongside it
     *
     * @return int<0, max>
     */
    public function getSuggestCount(string $name, bool $cached = true): int
    {
        $hint = $cached ? '' : self::LISTING_QUERY_TIMEOUT_HINT.' ';  // see getDependentCount()

        $compute = function () use ($name, $hint): int {
            $sql = 'SELECT '.$hint.'COUNT(*) count FROM suggester WHERE packageName = :name';

            return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, ['name' => $name]);
        };

        return $cached
            ? $this->getCachedCount('sug-count:'.strtolower($name), $compute)
            : max(0, $compute());
    }

    /**
     * TTL-only: dependent rows are keyed by the required package name while the writer operates on
     * the requiring package, so precise invalidation would need a deletion per required name. Keys
     * are lowercased because the packageName columns use a case-insensitive collation.
     *
     * @param callable(): int $compute
     *
     * @return int<0, max>
     */
    private function getCachedCount(string $cacheKey, callable $compute): int
    {
        try {
            $cached = $this->redisCache->get($cacheKey);
            if ($cached !== null) {
                return max(0, (int) $cached);
            }
        } catch (PredisException) {
            // a cache outage must not take the package page down with it
            return max(0, $compute());
        }

        $count = max(0, $compute());

        try {
            // zero is the one stale value anyone notices, a package's first dependent should show
            // up sooner than a day later
            $ttl = $count === 0 ? 3600 : 86400;
            // random variance spreads out the refresh of the most-requested packages
            $this->redisCache->setex($cacheKey, $ttl + random_int(0, intdiv($ttl, 6)), (string) $count);
        } catch (PredisException) {
            // nothing to do, the count is correct it just stays uncached this time
        }

        return $count;
    }

    /**
     * @return array<array{id: int, name: string, description: string|null, type: string|null, language: string|null, abandoned: int, replacementPackage: string|null}>
     */
    public function getSuggests(string $name, int $offset = 0, int $limit = 15): array
    {
        $sql = 'SELECT '.self::LISTING_QUERY_TIMEOUT_HINT.' p.id, p.name, p.description, p.type, p.language, p.abandoned, p.replacementPackage, p.frozen
            FROM package p INNER JOIN (
                SELECT DISTINCT package_id FROM suggester WHERE packageName = :name
            ) x ON x.package_id = p.id
            ORDER BY p.name ASC LIMIT '.((int) $limit).' OFFSET '.((int) $offset);

        $args = ['name' => $name];
        $suppressed = PackageFreezeReason::suppressingValues();

        $res = [];
        /** @var array{id: int, name: string, description: string|null, type: string|null, language: string|null, abandoned: bool, replacementPackage: string|null, frozen: string|null} $row */
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $args) as $row) {
            // suppressed rows are dropped here rather than in SQL, see getDependents()
            if (\in_array($row['frozen'], $suppressed, true)) {
                continue;
            }
            unset($row['frozen']);

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

        return (int) ($result[0]['count'] ?? 0);
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
            ->andWhere('(p.frozen IS NULL OR p.frozen NOT IN (:suppressed))')
            ->setParameter('suppressed', PackageFreezeReason::suppressingCases())
            ->orderBy('p.id', 'DESC');

        return $qb;
    }

    /**
     * Gets the most recent extension packages created
     */
    public function getQueryBuilderForNewestExtensionPackages(): QueryBuilder
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('p')
            ->from('App\Entity\Package', 'p')
            ->where('p.abandoned = false')
            ->andWhere('(p.frozen IS NULL OR p.frozen NOT IN (:suppressed))')
            ->setParameter('suppressed', PackageFreezeReason::suppressingCases())
            ->andWhere("(p.type = 'php-ext' OR p.type = 'php-ext-zend')")
            ->orderBy('p.id', 'DESC');

        return $qb;
    }
}
