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

use App\Audit\AuditLogSearchType;
use Composer\Pcre\Preg;
use Doctrine\ORM\QueryBuilder;

class PackageNameFilter extends AbstractAdminAwareTextFilter
{
    protected function applyFilter(QueryBuilder $qb, string $paramName, string $pattern, bool $useWildcard): QueryBuilder
    {
        // The (type, name) search index is the actual, fully-selective filter.
        $this->applySearchIndexFilter($qb, $paramName, $pattern, $useWildcard, AuditLogSearchType::Package);

        // For a "vendor/package" query, also constrain the indexed vendor column: it can't narrow
        // the result further (every match already has that vendor) but it hands the optimizer an
        // indexed access path into audit_log, avoiding a backward PK scan for the ORDER BY ... LIMIT.
        if (str_contains($pattern, '/')) {
            $vendor = Preg::replace('{/.*$}', '', mb_strtolower($pattern));
            // Skip when the vendor segment itself is a wildcard (e.g. "ven*/pkg" -> "ven%/pkg"),
            // since `a.vendor = 'ven%'` would match nothing and wrongly empty the result set.
            if (!$useWildcard || !str_contains($vendor, '%')) {
                $qb->setParameter('pkgVendor', $vendor);
                $qb->andWhere('a.vendor = :pkgVendor');
            }
        }

        return $qb;
    }
}
