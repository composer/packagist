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
use App\QueryFilter\QueryFilterInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\InputBag;

class ActorFilter implements QueryFilterInterface
{
    final private function __construct(
        private readonly string $key,
        private readonly string $username = '',
    ) {}

    public function filter(QueryBuilder $qb): QueryBuilder
    {
        if ($this->username === '') {
            return $qb;
        }

        $qb->innerJoin(User::class, 'u', 'WITH', 'a.actorId = u.id')
            ->andWhere('u.username LIKE :username')
            ->setParameter('username', '%' . $this->username . '%');

        return $qb;
    }

    public function getSelectedValue(): mixed
    {
        return $this->username;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @param InputBag<string> $bag
     */
    public static function fromQuery(InputBag $bag, string $key = 'actor'): static
    {
        $value = $bag->get($key, '');

        if (!is_string($value) || $value === '') {
            return new static($key);
        }

        return new static($key, trim($value));
    }
}
