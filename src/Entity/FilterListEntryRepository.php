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

use App\FilterList\FilterLists;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FilterListEntry>
 */
class FilterListEntryRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
    ) {
        parent::__construct($registry, FilterListEntry::class);
    }

    /**
     * @return list<FilterListEntry>
     */
    public function getEntriesInList(FilterLists $list): array
    {
        return $this->createQueryBuilder('fl')
            ->where('fl.list = :list')
            ->setParameter('list', $list)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<FilterListEntry>
     */
    public function getPackageVersionsFlaggedAsMalwareForPackage(Package $package): array
    {
        return $this->createQueryBuilder('fl')
            ->where('fl.packageName = :packageName')
            ->andWhere('fl.list IN (:lists)')
            ->setParameter('packageName', $package->getName())
            ->setParameter('lists', FilterLists::malwareListsValues(), ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<FilterListEntry>
     */
    public function getPackageEntries(string $packageName): array
    {
        return $this->createQueryBuilder('fl')
            ->where('fl.packageName = :packageName')
            ->setParameter('packageName', $packageName)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string> $packageNames
     * @return array<string, non-empty-list<array{version: string, list: string, reason: string|null}>>
     */
    public function getAllPackageEntriesMap(array $packageNames): array
    {
        $entries = $this->createQueryBuilder('fl')
            ->where('fl.packageName IN (:packageNames)')
            ->setParameter('packageNames', $packageNames, ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();

        $mappedData = [];
        foreach ($entries as $entry) {
            $mappedData[$entry->getPackageName()][] = [
                'version' => $entry->getVersion(),
                'list' => $entry->getList()->value,
                'reason' => $entry->getReason(),
            ];
        }

        return $mappedData;
    }
}
