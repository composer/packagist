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
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<SlugReservation>
 */
class SlugReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SlugReservation::class);
    }

    /**
     * Whether the slug is currently held by an active (not yet released) reservation.
     *
     * Reservations owned by $exceptOrgId are ignored so an organization can reclaim a
     * slug it previously freed by renaming (e.g. acme -> acme-inc -> acme).
     */
    public function isReserved(string $slug, ?Ulid $exceptOrgId = null): bool
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.activeSlug = :slug')
            ->setParameter('slug', $slug);

        if ($exceptOrgId !== null) {
            $qb->andWhere('r.orgId != :orgId')
                ->setParameter('orgId', $exceptOrgId, UlidType::NAME);
        }

        return (bool) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * The active (not yet released) reservation an organization holds for this slug, if any.
     */
    public function findActiveForOrg(string $slug, Ulid $orgId): ?SlugReservation
    {
        return $this->createQueryBuilder('r')
            ->where('r.activeSlug = :slug')
            ->andWhere('r.orgId = :orgId')
            ->setParameter('slug', $slug)
            ->setParameter('orgId', $orgId, UlidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
