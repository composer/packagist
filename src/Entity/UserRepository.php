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
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 *
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Admin user directory: every account, most-recently-registered first, optionally narrowed by a
     * username/email search term, freeze status, registration date range, 2FA status and GitHub
     * account link. The package count is computed with a correlated subquery against the maintainers
     * join table rather than joining/grouping, so users with zero packages are still included and no
     * row duplication needs to be untangled.
     *
     * $frozenFilter accepts 'none', 'any' or a UserFreezeReason::value; $twoFactorFilter 'enabled'
     * or 'disabled'; $githubLinkedFilter 'yes' or 'no'. Null skips that filter.
     */
    public function getUsersQueryBuilder(
        ?string $search = null,
        ?string $frozenFilter = null,
        ?\DateTimeImmutable $registeredFrom = null,
        ?\DateTimeImmutable $registeredTo = null,
        ?string $twoFactorFilter = null,
        ?string $githubId = null,
        ?string $githubLinkedFilter = null,
    ): QueryBuilder {
        $packageCountQb = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(pkg.id)')
            ->from(Package::class, 'pkg')
            ->innerJoin('pkg.maintainers', 'maint')
            ->where('maint = u');

        $qb = $this->createQueryBuilder('u')
            ->select('u')
            ->addSelect('('.$packageCountQb->getDQL().') AS packageCount')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC');

        if ($search !== null && $search !== '') {
            $qb->andWhere('u.usernameCanonical LIKE :search OR u.emailCanonical LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        match ($frozenFilter) {
            'none' => $qb->andWhere('u.frozen IS NULL'),
            'any' => $qb->andWhere('u.frozen IS NOT NULL'),
            'spam', 'bad_actor', 'temporary' => $qb->andWhere('u.frozen = :frozenReason')
                ->setParameter('frozenReason', UserFreezeReason::from($frozenFilter)),
            default => null,
        };

        if ($registeredFrom !== null) {
            $qb->andWhere('u.createdAt >= :registeredFrom')
                ->setParameter('registeredFrom', $registeredFrom);
        }

        if ($registeredTo !== null) {
            $qb->andWhere('u.createdAt <= :registeredTo')
                ->setParameter('registeredTo', $registeredTo);
        }

        match ($twoFactorFilter) {
            'enabled' => $qb->andWhere('u.totpSecret IS NOT NULL'),
            'disabled' => $qb->andWhere('u.totpSecret IS NULL'),
            default => null,
        };

        if ($githubId !== null && $githubId !== '') {
            $qb->andWhere('u.githubId = :githubId')
                ->setParameter('githubId', $githubId);
        }

        match ($githubLinkedFilter) {
            'yes' => $qb->andWhere('u.githubId IS NOT NULL'),
            'no' => $qb->andWhere('u.githubId IS NULL'),
            default => null,
        };

        return $qb;
    }

    public function findOneByUsernameOrEmail(string $usernameOrEmail): ?User
    {
        if (Preg::isMatch('/^.+\@\S+\.\S+$/', $usernameOrEmail)) {
            $user = $this->findOneBy(['emailCanonical' => $usernameOrEmail]);
            if (null !== $user) {
                return $user;
            }
        }

        return $this->findOneBy(['usernameCanonical' => $usernameOrEmail]);
    }

    public function usernameExists(string $username): bool
    {
        return (bool) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.usernameCanonical = :username')
            ->setParameter('username', mb_strtolower($username))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param string[]               $usernames
     * @param ?array<string, 'ASC'|'asc'|'DESC'|'desc'> $orderBy
     *
     * @return array<string, User>
     */
    public function findEnabledUsersByUsername(array $usernames, ?array $orderBy = null): array
    {
        $matches = $this->findBy([
            'usernameCanonical' => $usernames,
            'enabled' => true,
            'frozen' => null,
        ], $orderBy);

        $users = [];
        foreach ($matches as $match) {
            $users[$match->getUsernameCanonical()] = $match;
        }

        return $users;
    }

    /**
     * @return list<User>
     */
    public function findUsersMissingApiToken(): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.apiToken IS NULL');

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<User>
     */
    public function findUsersMissingSafeApiToken(): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.safeApiToken IS NULL')
            ->setMaxResults(500);

        return $qb->getQuery()->getResult();
    }

    public function getPackageMaintainersQueryBuilder(Package $package, ?User $excludeUser = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('u')
            ->select('u')
            ->innerJoin('u.packages', 'p', 'WITH', 'p.id = :packageId')
            ->setParameter(':packageId', $package->getId())
            ->orderBy('u.usernameCanonical', 'ASC');

        if ($excludeUser) {
            $qb->andWhere('u.id <> :userId')
                ->setParameter(':userId', $excludeUser->getId());
        }

        return $qb;
    }
}
