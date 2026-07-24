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
 * @extends ServiceEntityRepository<OrganizationTeam>
 */
class OrganizationTeamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationTeam::class);
    }

    /**
     * An org's teams: the two system teams (`owners`, `all organization members`) first, then
     * custom teams by name. The two system teams sort by name here; callers that need a fixed
     * system-team order (e.g. Owners before All organization members) reorder explicitly.
     *
     * @return list<OrganizationTeam>
     */
    public function findByOrg(Ulid $orgId): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.organization = :orgId')
            ->setParameter('orgId', $orgId, 'ulid')
            // 'system' sorts before 'custom' alphabetically, which is the order we want.
            ->orderBy('t.kind', 'DESC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByOrgAndTeamId(Ulid $orgId, Ulid $teamId): ?OrganizationTeam
    {
        return $this->createQueryBuilder('t')
            ->where('t.organization = :orgId')
            ->andWhere('t.teamId = :teamId')
            ->setParameter('orgId', $orgId, 'ulid')
            ->setParameter('teamId', $teamId, 'ulid')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByOrgSlugAndTeamId(string $slug, Ulid $teamId): ?OrganizationTeam
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.organization', 'o')
            ->where('o.slug = :slug')
            ->andWhere('t.teamId = :teamId')
            ->setParameter('slug', $slug)
            ->setParameter('teamId', $teamId, 'ulid')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
