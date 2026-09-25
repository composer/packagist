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

class VendorFilter extends AbstractTextFilter
{
    protected static function key(): string
    {
        return 'vendor';
    }

    protected function applyFilter(QueryBuilder $qb, string $value): QueryBuilder
    {
        return $qb->andWhere('t.vendor = :vendor')
            ->setParameter('vendor', $value);
    }
}
