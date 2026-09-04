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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

abstract class AbstractRegisteredDateFilter implements QueryFilterInterface
{
    final private function __construct(
        protected readonly string $value,
        protected readonly ?\DateTimeImmutable $date,
    ) {
    }

    abstract protected static function key(): string;

    /**
     * @return '>='|'>'|'<='|'<'
     */
    abstract protected static function operator(): string;

    /**
     * Whether the bound covers the whole named day rather than its first instant. Kept separate
     * from operator() so a new comparison does not silently inherit a boundary.
     */
    abstract protected static function endOfDay(): bool;

    /**
     * @param InputBag<string> $bag
     */
    final public static function fromQuery(InputBag $bag): static
    {
        $value = trim($bag->getString(static::key()));
        if ($value === '') {
            return new static($value, null);
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException(\sprintf('Invalid %s date', static::key()));
        }

        // `!Y-m-d` already yields 00:00:00, so only the end-of-day bound needs adjusting.
        if (static::endOfDay()) {
            $date = $date->setTime(23, 59, 59);
        }

        return new static($value, $date);
    }

    final public function filter(QueryBuilder $qb): QueryBuilder
    {
        if ($this->date === null) {
            return $qb;
        }

        return $qb->andWhere(\sprintf('u.createdAt %s :%s', static::operator(), static::key()))
            ->setParameter(static::key(), $this->date);
    }

    final public function getKey(): string
    {
        return static::key();
    }

    final public function getSelectedValue(): string
    {
        return $this->value;
    }
}
