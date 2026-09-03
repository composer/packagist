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
use App\Log\TransparencyLogSearchType;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;

/**
 * Filters by a username in one role. It matches the username at the time of the event, so the result
 * can include more than one account if the username changed owner. {@see UserIdFilter} searches by
 * account.
 */
abstract class AbstractSearchIndexFilter extends AbstractTextFilter
{
    abstract protected static function type(): TransparencyLogSearchType;

    protected function applyFilter(QueryBuilder $qb, string $value): QueryBuilder
    {
        // one alias per filter, so the User and Actor filters can be used together
        $alias = static::key().'Search';

        return $qb
            ->join(PackageTransparencyLogSearch::class, $alias, Join::WITH, $alias.'.leafIndex = t.leafIndex')
            ->andWhere($alias.'.type = :'.$alias.'Type')
            ->andWhere($alias.'.name = :'.$alias.'Name')
            ->setParameter($alias.'Type', static::type()->value)
            ->setParameter($alias.'Name', mb_strtolower($value))
            // order by the index columns, so MySQL reads the index in page order and does not sort
            ->orderBy($alias.'.datetime', 'DESC')
            ->addOrderBy($alias.'.leafIndex', 'DESC');
    }
}
