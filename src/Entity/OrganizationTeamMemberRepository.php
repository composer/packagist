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
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<OrganizationTeamMember>
 */
class OrganizationTeamMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationTeamMember::class);
    }

    /**
     * Load the {@see User} behind a team membership by their username (canonicalised here) in a single
     * joined query. Returns null when the user does not exist or is not a member of the team, so callers
     * cannot tell the two cases apart and no user id is exposed.
     */
    public function findTeamMember(Ulid $teamId, string $username): ?User
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->innerJoin(OrganizationTeamMember::class, 'm', Join::WITH, 'm.userId = u.id')
            ->where('m.teamId = :teamId')
            ->andWhere('u.usernameCanonical = :username')
            ->setParameter('teamId', $teamId, 'ulid')
            ->setParameter('username', mb_strtolower($username))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Load the {@see User} behind an organization membership by the org slug and their username
     * (canonicalised here) in a single joined query. Membership is the union of team memberships, so
     * DISTINCT collapses a user who belongs to several teams. Returns null when the user does not exist
     * or is not a member of the org, so callers cannot tell the two cases apart and no user id is exposed.
     */
    public function findOrgMember(string $orgSlug, string $username): ?User
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->distinct()
            ->from(User::class, 'u')
            ->innerJoin(OrganizationTeamMember::class, 'm', Join::WITH, 'm.userId = u.id')
            ->innerJoin(Organization::class, 'o', Join::WITH, 'o.id = m.orgId')
            ->where('o.slug = :slug')
            ->andWhere('u.usernameCanonical = :username')
            ->setParameter('slug', $orgSlug)
            ->setParameter('username', mb_strtolower($username))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<OrganizationTeamMember>
     */
    public function findByTeam(Ulid $teamId): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.teamId = :teamId')
            ->setParameter('teamId', $teamId, 'ulid')
            ->orderBy('m.addedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Number of members in a team. For the `owners` team this is the owner count that gates
     * removing the last owner.
     */
    public function countByTeam(Ulid $teamId): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.userId)')
            ->where('m.teamId = :teamId')
            ->setParameter('teamId', $teamId, 'ulid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * All membership rows for an org (a user appears once per team they belong to).
     *
     * @return list<OrganizationTeamMember>
     */
    public function findByOrg(Ulid $orgId): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.orgId = :orgId')
            ->setParameter('orgId', $orgId, 'ulid')
            ->orderBy('m.addedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether the user belongs to any team in the org, i.e. is an org member.
     */
    public function isMemberOfOrg(Ulid $orgId, int $userId): bool
    {
        return (bool) $this->createQueryBuilder('m')
            ->select('COUNT(m.userId)')
            ->where('m.orgId = :orgId')
            ->andWhere('m.userId = :userId')
            ->setParameter('orgId', $orgId, 'ulid')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Whether the user is a member of the org's `owners` team, i.e. an owner.
     */
    public function isOwner(Ulid $ownersTeamId, int $userId): bool
    {
        return (bool) $this->createQueryBuilder('m')
            ->select('COUNT(m.userId)')
            ->where('m.teamId = :teamId')
            ->andWhere('m.userId = :userId')
            ->setParameter('teamId', $ownersTeamId, 'ulid')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
