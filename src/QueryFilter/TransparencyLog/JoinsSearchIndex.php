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

namespace App\QueryFilter\TransparencyLog;

use App\Entity\PackageTransparencyLog;
use App\Entity\PackageTransparencyLogSearch;
use Doctrine\ORM\Query\Expr\From;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;

trait JoinsSearchIndex
{
    private static function joinSearchIndex(QueryBuilder $qb, string $alias): QueryBuilder
    {
        if (self::searchIndexAliases($qb) !== []) {
            return $qb->join(PackageTransparencyLogSearch::class, $alias, Join::ON, $alias.'.leafIndex = t.leafIndex');
        }

        // The list must be sorted by the index columns, or MySQL sorts all entries of the person before
        // it can return a page. Doctrine's cursor paginator fails on the link to the next page when the
        // sort columns are in a joined table, so the query starts from the index and joins the entry.
        return $qb
            ->resetDQLPart('from')
            ->from(PackageTransparencyLogSearch::class, $alias)
            ->join(PackageTransparencyLog::class, 't', Join::ON, 't.leafIndex = '.$alias.'.leafIndex')
            ->orderBy($alias.'.datetime', \SortDirection::Descending)
            ->addOrderBy($alias.'.leafIndex', \SortDirection::Descending);
    }

    /**
     * @return list<string>
     */
    private static function searchIndexAliases(QueryBuilder $qb): array
    {
        $aliases = [];
        foreach ($qb->getDQLPart('from') as $from) {
            \assert($from instanceof From);
            if ($from->getFrom() === PackageTransparencyLogSearch::class) {
                $aliases[] = $from->getAlias();
            }
        }
        foreach ($qb->getDQLPart('join') as $joins) {
            foreach ($joins as $join) {
                if ($join->getJoin() === PackageTransparencyLogSearch::class) {
                    $aliases[] = (string) $join->getAlias();
                }
            }
        }

        return $aliases;
    }
}
