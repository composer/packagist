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

use App\Log\TransparencyLogSearchType;
use Doctrine\ORM\QueryBuilder;

/**
 * Filters by a username in one role. It matches the username at the time of the event, so the result
 * can include more than one account if the username changed owner. {@see UserIdFilter} searches by
 * account.
 */
abstract class AbstractSearchIndexFilter extends AbstractTextFilter
{
    use JoinsSearchIndex;

    abstract protected static function type(): TransparencyLogSearchType;

    protected function applyFilter(QueryBuilder $qb, string $value): QueryBuilder
    {
        // one alias per filter, so the User and Actor filters can be used together
        $alias = static::key().'Search';

        return self::joinSearchIndex($qb, $alias)
            ->andWhere($alias.'.type = :'.$alias.'Type')
            ->andWhere($alias.'.name = :'.$alias.'Name')
            ->setParameter($alias.'Type', static::type()->value)
            ->setParameter($alias.'Name', mb_strtolower($value));
    }
}
