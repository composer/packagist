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
        $qb->setParameter($paramName, $pattern);

        $qb->innerJoin(User::class, 'targetUser', 'WITH', 'a.userId = targetUser.id');

        if ($useWildcard) {
            $qb->andWhere('targetUser.username LIKE :' . $paramName);
        } else {
            $qb->andWhere('targetUser.username = :' . $paramName);
        }

        return $qb;
    }

}
