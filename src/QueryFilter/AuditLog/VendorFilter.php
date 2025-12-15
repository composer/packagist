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

namespace App\QueryFilter\AuditLog;

use Doctrine\ORM\QueryBuilder;

class VendorFilter extends AbstractAdminAwareTextFilter
{
    protected function applyFilter(QueryBuilder $qb, string $paramName, string $pattern, bool $useWildcard): QueryBuilder
    {
        $qb->setParameter($paramName, $pattern);

        if ($useWildcard) {
            $qb->andWhere('a.vendor LIKE :' . $paramName);
        } else {
            $qb->andWhere('a.vendor = :' . $paramName);
        }

        return $qb;
    }
}
