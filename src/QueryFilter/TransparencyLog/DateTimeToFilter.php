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

use Doctrine\ORM\QueryBuilder;

class DateTimeToFilter extends AbstractDateTimeFilter
{
    protected static function key(): string
    {
        return 'datetime_to';
    }

    protected function applyFilter(QueryBuilder $qb, \DateTimeImmutable $dateTime): QueryBuilder
    {
        return $qb->andWhere('t.datetime <= :datetime_to')
            ->setParameter('datetime_to', $dateTime);
    }
}
