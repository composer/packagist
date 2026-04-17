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
use App\FilterList\FilterSources;
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
    public function getEntriesInList(FilterLists $list, FilterSources $source): array
    {
        return $this->createQueryBuilder('fl')
            ->where('fl.list = :list')
            ->andWhere('fl.source = :source')
            ->setParameter('list', $list)
            ->setParameter('source', $source)
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
            ->andWhere('fl.list = :malware')
            ->setParameter('packageName', $package->getName())
            ->setParameter('malware', FilterLists::MALWARE)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<FilterListEntry>
     */
    public function getPackageEntries(string $packageName, FilterLists $list): array
    {
        return $this->createQueryBuilder('fl')
            ->where('fl.packageName = :packageName')
            ->andWhere('fl.list = :list')
            ->setParameter('packageName', $packageName)
            ->setParameter('list', $list)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string> $packageNames
     *
     * @return array<string, non-empty-list<array{version: string, list: string, reason: string|null, publicId: string|null, source: string}>>
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
                'publicId' => $entry->getPublicId(),
                'source' => $entry->getSource()->value,
            ];
        }

        return $mappedData;
    }
}
