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

class VendorFilter implements QueryFilterInterface
{
    final private function __construct(
        private readonly string $key,
        private readonly string $vendor = '',
    ) {}

    public function filter(QueryBuilder $qb): QueryBuilder
    {
        if ($this->vendor === '') {
            return $qb;
        }

        $qb->andWhere('a.vendor LIKE :vendor')
            ->setParameter('vendor', '%' . $this->vendor . '%');

        return $qb;
    }

    public function getSelectedValue(): mixed
    {
        return $this->vendor;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @param InputBag<string> $bag
     */
    public static function fromQuery(InputBag $bag, string $key = 'vendor'): static
    {
        $value = $bag->get($key, '');

        if (!is_string($value) || $value === '') {
            return new static($key);
        }

        return new static($key, trim($value));
    }
}
