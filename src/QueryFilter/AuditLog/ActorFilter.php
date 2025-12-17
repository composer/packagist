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

class ActorFilter extends AbstractAdminAwareTextFilter
{
    protected function applyFilter(QueryBuilder $qb, string $paramName, string $pattern, bool $useWildcard): QueryBuilder
    {
        $qb->setParameter($paramName, $pattern);

        if ($useWildcard) {
            $qb->setParameter($paramName, sprintf('"%s"', $pattern));
            $qb->andWhere("JSON_EXTRACT(a.attributes, '$.actor.username') LIKE :" . $paramName);
        } else {
            $qb->setParameter($paramName, $pattern);
            $qb->andWhere("JSON_EXTRACT(a.attributes, '$.actor.username') = :" . $paramName);
        }

        return $qb;
    }
}
