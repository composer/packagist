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

abstract class AbstractDateTimeFilter implements QueryFilterInterface
{
    use JoinsSearchIndex;

    final private function __construct(
        private readonly string $value,
    ) {
    }

    final public function filter(QueryBuilder $qb): QueryBuilder
    {
        $dateTime = $this->getDateTime();

        if ($dateTime === null) {
            return $qb;
        }

        // The search index orders by its own copy of the datetime, so the range must bound that
        // copy too, or MySQL walks every index row for the name before filtering
        foreach (['t', ...self::searchIndexAliases($qb)] as $alias) {
            $qb->andWhere($alias.'.datetime '.static::operator().' :'.static::key());
        }

        return $qb->setParameter(static::key(), $dateTime);
    }

    abstract protected static function key(): string;

    abstract protected static function operator(): string;

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
