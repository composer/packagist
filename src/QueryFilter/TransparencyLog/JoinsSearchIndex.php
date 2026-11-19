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

use App\Entity\PackageTransparencyLogSearch;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;

trait JoinsSearchIndex
{
    private static function joinSearchIndex(QueryBuilder $qb, string $alias): QueryBuilder
    {
        return $qb
            ->join(PackageTransparencyLogSearch::class, $alias, Join::WITH, $alias.'.leafIndex = t.leafIndex')
            // order by the index columns, so MySQL reads the index in page order and does not sort
            ->orderBy($alias.'.datetime', 'DESC')
            ->addOrderBy($alias.'.leafIndex', 'DESC');
    }
}
