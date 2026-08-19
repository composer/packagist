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

use App\Audit\TransparencyLogType;
use App\QueryFilter\QueryFilterInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\InputBag;

class TransparencyLogTypeFilter implements QueryFilterInterface
{
    /**
     * @param list<string> $types
     */
    private function __construct(
        private readonly array $types = [],
    ) {
    }

    public function filter(QueryBuilder $qb): QueryBuilder
    {
        if ($this->types === []) {
            return $qb;
        }

        return $qb->andWhere('t.type IN (:types)')
            ->setParameter('types', $this->types);
    }

    public function getSelectedValue(): mixed
    {
        return $this->types;
    }

    public function getKey(): string
    {
        return 'type';
    }

    /**
     * @param InputBag<string> $bag
     */
    public static function fromQuery(InputBag $bag, bool $allowHiddenTypes = false): self
    {
        $hidden = $allowHiddenTypes ? [] : array_map(
            static fn (TransparencyLogType $type): string => $type->value,
            TransparencyLogType::temporarilyHiddenTypes(),
        );

        $types = array_filter(
            $bag->all('type'),
            static fn (mixed $value): bool => \is_string($value)
                && TransparencyLogType::tryFrom($value) !== null
                && !\in_array($value, $hidden, true),
        );

        return new self(array_values($types));
    }
}
