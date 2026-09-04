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

abstract class AbstractNullableColumnFilter implements QueryFilterInterface
{
    final private function __construct(protected readonly string $value)
    {
    }

    abstract protected static function key(): string;

    abstract protected static function column(): string;

    /**
     * @return array{string, string} the query values meaning "column set" and "column null"
     */
    abstract protected static function values(): array;

    /**
     * @param InputBag<string> $bag
     */
    final public static function fromQuery(InputBag $bag): static
    {
        $value = trim($bag->getString(static::key()));

        if ($value !== '' && !\in_array($value, static::values(), true)) {
            throw new BadRequestHttpException(\sprintf('Unknown %s filter', static::key()));
        }

        return new static($value);
    }

    final public function filter(QueryBuilder $qb): QueryBuilder
    {
        [$set, $null] = static::values();

        return match ($this->value) {
            $set => $qb->andWhere(static::column().' IS NOT NULL'),
            $null => $qb->andWhere(static::column().' IS NULL'),
            default => $qb,
        };
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
