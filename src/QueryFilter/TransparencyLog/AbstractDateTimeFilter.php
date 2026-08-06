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
 * A datetime bound over the public transparency log. An unparseable value is ignored rather than
 * rejected, so a hand-edited URL degrades to an unfiltered list.
 */
abstract class AbstractDateTimeFilter implements QueryFilterInterface
{
    final private function __construct(
        private readonly string $value,
    ) {
    }

    final public function filter(QueryBuilder $qb): QueryBuilder
    {
        $dateTime = $this->getDateTime();

        return $dateTime === null ? $qb : $this->applyFilter($qb, $dateTime);
    }

    abstract protected static function key(): string;

    abstract protected function applyFilter(QueryBuilder $qb, \DateTimeImmutable $dateTime): QueryBuilder;

    final public function getKey(): string
    {
        return static::key();
    }

    public function getSelectedValue(): mixed
    {
        return $this->value;
    }

    public function getDateTime(): ?\DateTimeImmutable
    {
        if ($this->value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($this->value);
        } catch (\Exception) {
            return null;
        }
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
