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
