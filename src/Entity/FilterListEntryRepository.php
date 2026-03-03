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

use App\FilterList\FilterListCategories;
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
    public function getPackageVersionsFlaggedAsMalwareInList(FilterLists $list): array
    {
        return $this->createQueryBuilder('fl')
            ->where('fl.list = :list')
            ->andWhere('fl.category = :category')
            ->setParameter('list', $list)
            ->setParameter('category', FilterListCategories::MALWARE)
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
            ->andWhere('fl.category = :category')
            ->setParameter('packageName', $package->getName())
            ->setParameter('category', 'malware')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<FilterListEntry>
     */
    public function getPackageEntriesForCategory(string $packageName, FilterListCategories $category): array
    {
        return $this->createQueryBuilder('fl')
            ->where('fl.packageName = :packageName')
            ->andWhere('fl.category = :category')
            ->setParameter('packageName', $packageName)
            ->setParameter('category', $category)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string> $packageNames
     * @return array<string, non-empty-list<array{version: string, list: string, category: string}>>
     */
    public function getPackageVersionsFlaggedAsMalwareForPackageNames(array $packageNames): array
    {
        $entries = $this->createQueryBuilder('fl')
            ->where('fl.packageName IN (:packageNames)')
            ->setParameter('packageNames', $packageNames, ArrayParameterType::STRING)
            ->andWhere('fl.category = :category')
            ->setParameter('category', FilterListCategories::MALWARE)
            ->getQuery()
            ->getResult();

        $malwarePackageVersions = [];
        foreach ($entries as $entry) {
            $malwarePackageVersions[$entry->getPackageName()][] = [
                'version' => $entry->getVersion(),
                'list' => $entry->getList()->value,
                'category' => $entry->getCategory()->value,
            ];
        }

        return $malwarePackageVersions;
    }
}
