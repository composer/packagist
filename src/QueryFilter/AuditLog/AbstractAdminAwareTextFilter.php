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

use App\QueryFilter\QueryFilterInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\InputBag;

abstract class AbstractAdminAwareTextFilter implements QueryFilterInterface
{
    final private function __construct(
        protected readonly string $key,
        protected readonly string $value,
        protected readonly bool $isAdmin
    ) {}

    /**
     * Apply exact or wildcard matching based on admin status.
     *
     * For admins: Supports * as wildcard. If no * is present, wraps with wildcards for substring search.
     * For non-admins: Exact match only.
     */
    final public function filter(QueryBuilder $qb): QueryBuilder
    {
        if ($this->value === '') {
            return $qb;
        }

        $paramName = $this->getKey();

        if ($this->isAdmin) {
            $escapedValue = addcslashes($this->value, '%_\\');
            $pattern = str_replace('*', '%', $escapedValue);
            $usePartialMatching = str_contains($pattern, '%');
        } else {
            $pattern = $this->value;
            $usePartialMatching = false;
        }


        return $this->applyFilter($qb, $paramName, $pattern, $usePartialMatching);
    }

    /**
     * @param QueryBuilder $qb
     * @param string $paramName The parameter name to use in the WHERE clause
     * @param bool $useWildcard
     */
    abstract protected function applyFilter(
        QueryBuilder $qb,
        string $paramName,
        string $pattern,
        bool $useWildcard
    ): QueryBuilder;

    public function getSelectedValue(): mixed
    {
        return $this->value;
    }

    /**
     * @param InputBag<string> $bag
     */
    public static function fromQuery(InputBag $bag, string $key, bool $isAdmin): static
    {
        $value = $bag->get($key, '');

        if (!is_string($value) || $value === '') {
            return new static($key, '', $isAdmin);
        }

        return new static($key, trim($value), $isAdmin);
    }

    public function getKey(): string
    {
        return $this->key;
    }
}
