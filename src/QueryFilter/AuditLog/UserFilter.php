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

use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\InputBag;

class UserFilter extends AbstractAdminAwareTextFilter
{
    protected function applyFilter(QueryBuilder $qb, string $paramName, string $pattern, bool $useWildcard): QueryBuilder
    {
        // If pattern is numeric search by userId
        if (is_numeric($pattern) && $this->isAdmin) {
            $qb->setParameter($paramName, (int) $pattern);
            $qb->andWhere('a.userId = :' . $paramName);
        } elseif ($useWildcard) {
            $qb->setParameter($paramName, sprintf('"%s"', $pattern));
            $qb->andWhere("JSON_EXTRACT(a.attributes, '$.user.username') LIKE :" . $paramName);
        } else {
            $qb->setParameter($paramName, $pattern);
            $qb->andWhere("JSON_EXTRACT(a.attributes, '$.user.username') = :" . $paramName);
        }

        return $qb;
    }

}
