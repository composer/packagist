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
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
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

    /**
     * @return list<User>
     */
    public function findUsersMissingApiToken(): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.apiToken IS NULL');

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
