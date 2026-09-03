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

class GithubIdFilter implements QueryFilterInterface
{
    private function __construct(private readonly string $value)
    {
    }

    /**
     * @param InputBag<string> $bag
     */
    public static function fromQuery(InputBag $bag): self
    {
        return new self(trim($bag->getString('github_id')));
    }

    public function filter(QueryBuilder $qb): QueryBuilder
    {
        if ($this->value === '') {
            return $qb;
        }

        return $qb->andWhere('u.githubId = :githubId')->setParameter('githubId', $this->value);
    }

    public function getKey(): string
    {
        return 'github_id';
    }

    public function getSelectedValue(): string
    {
        return $this->value;
    }
}
