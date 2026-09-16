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

use App\Entity\User;
use Doctrine\ORM\QueryBuilder;

/**
 * Filters down to entries about one user, by the canonical (lowercased) username. Matches the user
 * the event is about, not the actor.
 */
class UserFilter extends AbstractTextFilter
{
    protected static function key(): string
    {
        return 'user';
    }

    protected function applyFilter(QueryBuilder $qb, string $value): QueryBuilder
    {
        return $qb
            ->andWhere(\sprintf('t.userId IN (SELECT u.id FROM %s u WHERE u.usernameCanonical = :user)', User::class))
            ->setParameter('user', mb_strtolower($value));
    }
}
