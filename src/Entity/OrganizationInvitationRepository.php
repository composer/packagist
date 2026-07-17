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

use App\Organization\Domain\InvitationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<OrganizationInvitation>
 */
class OrganizationInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationInvitation::class);
    }

    /**
     * All invitations for an org, newest first, for the owner's management view.
     *
     * @return list<OrganizationInvitation>
     */
    public function findByOrg(Ulid $orgId): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.orgId = :orgId')
            ->setParameter('orgId', $orgId, 'ulid')
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The live (pending and unexpired at $now) invitation for an org/email pair, if any. Used to enforce
     * the "no duplicate pending invitation" precondition. A pending row whose expiry has passed is not
     * live and does not block a fresh invitation.
     */
    public function findLiveForEmail(Ulid $orgId, string $emailCanonical, \DateTimeImmutable $now): ?OrganizationInvitation
    {
        return $this->createQueryBuilder('i')
            ->where('i.orgId = :orgId')
            ->andWhere('i.emailCanonical = :email')
            ->andWhere('i.status = :pending')
            ->andWhere('i.expiresAt >= :now')
            ->setParameter('orgId', $orgId, 'ulid')
            ->setParameter('email', $emailCanonical)
            ->setParameter('pending', InvitationStatus::Pending->value)
            ->setParameter('now', $now)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
