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
 * @extends ServiceEntityRepository<OrganizationMember>
 */
class OrganizationMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationMember::class);
    }

    public function findOneByOrgAndUser(Ulid $orgId, int $userId): ?OrganizationMember
    {
        return $this->findOneBy(['orgId' => $orgId, 'userId' => $userId]);
    }

    /**
     * Load the {@see User} behind an org membership by their username (canonicalised here) in a single
     * joined query. Returns null when the user does not exist or is not a member of the org, so callers
     * cannot tell the two cases apart and no user id is exposed.
     */
    public function findOrgMember(Ulid $orgId, string $username): ?User
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->innerJoin(OrganizationMember::class, 'm', Join::WITH, 'm.userId = u.id')
            ->where('m.orgId = :orgId')
            ->andWhere('u.usernameCanonical = :username')
            ->setParameter('orgId', $orgId, 'ulid')
            ->setParameter('username', mb_strtolower($username))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
