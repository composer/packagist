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

use Composer\Pcre\Preg;
use Doctrine\ORM\QueryBuilder;

class PackageNameFilter extends AbstractAdminAwareTextFilter
{
    protected function applyFilter(QueryBuilder $qb, string $paramName, string $pattern, bool $useWildcard): QueryBuilder
    {
        if ($useWildcard) {
            $qb->setParameter($paramName, \sprintf('"%s"', $pattern));
            $qb->andWhere("JSON_EXTRACT(a.attributes, '$.name') LIKE :".$paramName);
        } else {
            $qb->setParameter($paramName, $pattern);
            $qb->andWhere("JSON_EXTRACT(a.attributes, '$.name') = :".$paramName);
        }

        if (str_contains($pattern, '/')) {
            $vendor = Preg::replace('{/.*$}', '', $pattern);
            // The vendor pre-filter is an indexed exact match; skip it when the vendor segment
            // itself contains a wildcard (e.g. "ven*/pkg" -> pattern "ven%/pkg"), since
            // `a.vendor = 'ven%'` would match nothing and wrongly empty the result set.
            if (!$useWildcard || !str_contains($vendor, '%')) {
                $qb->setParameter('pkgVendor', $vendor);
                $qb->andWhere('a.vendor = :pkgVendor');
            }
        }

        return $qb;
    }
}
