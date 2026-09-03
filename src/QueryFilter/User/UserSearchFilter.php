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

namespace App\QueryFilter\User;

use App\QueryFilter\QueryFilterInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\InputBag;

class UserSearchFilter implements QueryFilterInterface
{
    private function __construct(private readonly string $value)
    {
    }

    /**
     * @param InputBag<string> $bag
     */
    public static function fromQuery(InputBag $bag): self
    {
        return new self(trim($bag->getString('search')));
    }

    public function filter(QueryBuilder $qb): QueryBuilder
    {
        if ($this->value === '') {
            return $qb;
        }

        return $qb->andWhere('(u.usernameCanonical LIKE :search OR u.emailCanonical LIKE :search)')
            ->setParameter('search', '%'.addcslashes(mb_strtolower($this->value), '%_\\').'%');
    }

    public function getKey(): string
    {
        return 'search';
    }

    public function getSelectedValue(): string
    {
        return $this->value;
    }
}
