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
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PackageTransparencyLogSearch>
 */
class PackageTransparencyLogSearchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PackageTransparencyLogSearch::class);
    }

    /**
     * Writes the search rows for the given entries in one statement. Call it in the same transaction
     * as the entries.
     *
     * INSERT IGNORE makes it safe to index an entry again, for example when the backfill and the
     * projector run at the same time.
     *
     * @param list<PackageTransparencyLog> $entries
     *
     * @return int rows added
     */
    public function index(array $entries): int
    {
        $values = [];
        foreach ($entries as $entry) {
            foreach ($entry->getSearchTerms() as $term) {
                $values[] = $term['type']->value;
                $values[] = $term['name'];
                $values[] = $entry->leafIndex;
                $values[] = $term['userId'];
                $values[] = $entry->datetime->format('Y-m-d H:i:s');
                $values[] = (int) $term['primaryRow'];
            }
        }

        if ($values === []) {
            return 0;
        }

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT IGNORE INTO package_transparency_log_search (type, name, leafIndex, userId, datetime, primaryRow) VALUES '
                .implode(', ', array_fill(0, \intdiv(\count($values), 6), '(?, ?, ?, ?, ?, ?)')),
            $values,
        );
    }

    /**
     * The given leaf indexes that already have search rows, so the backfill can skip them.
     *
     * @param list<int> $leafIndexes
     *
     * @return list<int>
     */
    public function filterIndexedLeafIndexes(array $leafIndexes): array
    {
        if ($leafIndexes === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT leafIndex FROM package_transparency_log_search WHERE leafIndex IN (?)',
            [$leafIndexes],
            [ArrayParameterType::INTEGER],
        );

        return array_map('intval', $rows);
    }
}
