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
     * An org's teams, the system `owners` team first, then custom teams by name.
     *
     * @return list<OrganizationTeam>
     */
    public function findByOrg(Ulid $orgId): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.orgId = :orgId')
            ->setParameter('orgId', $orgId, 'ulid')
            // 'system' sorts before 'custom' alphabetically, which is the order we want.
            ->orderBy('t.kind', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByOrgAndTeamId(Ulid $orgId, Ulid $teamId): ?OrganizationTeam
    {
        return $this->findOneBy(['orgId' => $orgId, 'teamId' => $teamId]);
    }
}
