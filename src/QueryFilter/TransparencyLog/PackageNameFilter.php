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

/**
 * Restricts to entries of one package, matched on the name in every entry rather than
 * on the live package table: an entry must stay readable after its package is gone, and the entry
 * announcing the deletion is the one that matters most. An unknown name simply yields no rows.
 */
class PackageNameFilter extends AbstractTextFilter
{
    protected static function key(): string
    {
        return 'package';
    }

    protected function applyFilter(QueryBuilder $qb, string $value): QueryBuilder
    {
        return $qb
            ->andWhere('t.packageName = :package')
            ->setParameter('package', $value);
    }
}
