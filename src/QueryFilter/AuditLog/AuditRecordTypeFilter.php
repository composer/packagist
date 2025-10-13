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

use App\Audit\AuditRecordType;
use App\QueryFilter\QueryFilterInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\InputBag;

class AuditRecordTypeFilter implements QueryFilterInterface
{
    /**
     * @param string[] $types
     */
    final private function __construct(
        private readonly string $key,
        private readonly array $types = [],
    ) {}

    public function filter(QueryBuilder $qb): QueryBuilder
    {
        if (count($this->types) === 0) {
            return $qb;
        }

        $qb->andWhere('a.type IN (:types)')
            ->setParameter('types', $this->types);

        return $qb;
    }

    public function getSelectedValue(): mixed
    {
        return $this->types;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @param InputBag<string> $bag
     */
    public static function fromQuery(InputBag $bag, string $key = 'type'): static
    {
        $values = $bag->all($key);

        if (empty($values)) {
            return new static($key);
        }

        $types = array_filter($values, fn (string $inputValue) => self::isValid($inputValue));

        return new static($key, array_values($types));
    }

    private static function isValid(string $value): bool
    {
        $enum = AuditRecordType::tryFrom($value);

        return $enum !== null;
    }
}
