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
 * @extends ServiceEntityRepository<SocketSupplyChainAlert>
 */
class SocketSupplyChainAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocketSupplyChainAlert::class);
    }

    /**
     * @return list<SocketSupplyChainAlert>
     */
    public function getPackageAlerts(string $packageName): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.packageName = :packageName')
            ->setParameter('packageName', $packageName)
            ->orderBy('a.version')
            ->addOrderBy('a.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * Swaps all stored alerts for $alerts in one transaction, so package pages never see a half-written set.
     *
     * @param list<SocketSupplyChainAlert> $alerts
     */
    public function replaceAll(array $alerts): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(static function () use ($em, $alerts): void {
            $em->createQuery('DELETE FROM '.SocketSupplyChainAlert::class.' a')->execute();
            foreach ($alerts as $alert) {
                $em->persist($alert);
            }
            $em->flush();
        });
    }
}
