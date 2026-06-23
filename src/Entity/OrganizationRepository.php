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

/**
 * @extends ServiceEntityRepository<Organization>
 */
class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    public function findOneBySlug(string $slug): ?Organization
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Live organizations owned by the given user, newest first.
     *
     * @return list<Organization>
     */
    public function findByOwner(User $user): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.createdBy = :userId')
            ->andWhere('o.deletedAt IS NULL')
            ->setParameter('userId', $user->getId())
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every organization, including soft-deleted ones, newest first. For admins only.
     *
     * @return list<Organization>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('o')
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether a live organization already uses this slug.
     */
    public function slugExists(string $slug, bool $includeDeleted = true): bool
    {
        $qb = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.slug = :slug')
            ->setParameter('slug', $slug);

        if (!$includeDeleted) {
            $qb->andWhere('o.deletedAt IS NULL');
        }

        return (bool) $qb->getQuery()->getSingleScalarResult();
    }
}
