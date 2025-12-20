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
use Symfony\Component\HttpFoundation\InputBag;

class PackageNameFilter extends AbstractAdminAwareTextFilter
{
    protected function applyFilter(QueryBuilder $qb, string $paramName, string $pattern, bool $useWildcard): QueryBuilder
    {
        if ($useWildcard) {
            $qb->setParameter($paramName, sprintf('"%s"', $pattern));
            $qb->andWhere("JSON_EXTRACT(a.attributes, '$.name') LIKE :" . $paramName);
        } else {
            $qb->setParameter($paramName, $pattern);
            $qb->andWhere("JSON_EXTRACT(a.attributes, '$.name') = :" . $paramName);
        }

        return $qb;
    }
}
