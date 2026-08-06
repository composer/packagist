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

use App\Entity\Package;
use Doctrine\ORM\QueryBuilder;

/**
 * Restricts to entries of one package. packageId is a plain column rather than an association, so the
 * name is resolved through a sub-query; an unknown name simply yields no rows.
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
            ->andWhere(\sprintf('t.packageId IN (SELECT p.id FROM %s p WHERE p.name = :package)', Package::class))
            ->setParameter('package', $value);
    }
}
