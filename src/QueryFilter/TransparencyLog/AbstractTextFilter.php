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

use App\QueryFilter\QueryFilterInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * A single-value text filter over the public transparency log. Matching is exact: unlike the audit log
 * filters there is no wildcard mode, since the page has no privileged audience.
 */
abstract class AbstractTextFilter implements QueryFilterInterface
{
    final private function __construct(
        protected readonly string $value,
    ) {
    }

    final public function filter(QueryBuilder $qb): QueryBuilder
    {
        if ($this->value === '') {
            return $qb;
        }

        return $this->applyFilter($qb, $this->value);
    }

    abstract protected static function key(): string;

    abstract protected function applyFilter(QueryBuilder $qb, string $value): QueryBuilder;

    final public function getKey(): string
    {
        return static::key();
    }

    public function getSelectedValue(): mixed
    {
        return $this->value;
    }

    /**
     * @param InputBag<string> $bag
     */
    final public static function fromQuery(InputBag $bag): static
    {
        $value = $bag->get(static::key(), '');

        return new static(\is_string($value) ? trim($value) : '');
    }
}
