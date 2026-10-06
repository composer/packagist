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

namespace App\Model;

use Doctrine\ORM\QueryBuilder;
use Pagerfanta\Adapter\AdapterInterface;
use Pagerfanta\Doctrine\ORM\QueryAdapter;

/**
 * Counts at most $maxResults rows, so the count stays cheap on a large table. Pair it with
 * Pagerfanta::setMaxNbPages() so no page past the cap is reachable.
 *
 * For queries without joins: it counts the root entity's `id`.
 *
 * @template T
 *
 * @template-implements AdapterInterface<T>
 */
class CappedCountQueryAdapter implements AdapterInterface
{
    /**
     * @var QueryAdapter<T>
     */
    private QueryAdapter $inner;

    /**
     * @param positive-int $maxResults
     */
    public function __construct(
        private QueryBuilder $queryBuilder,
        private int $maxResults,
    ) {
        $this->inner = new QueryAdapter($queryBuilder, false, false);
    }

    /**
     * @return int<0, max>
     */
    public function getNbResults(): int
    {
        $ids = (clone $this->queryBuilder)
            ->select($this->queryBuilder->getRootAliases()[0].'.id')
            ->setFirstResult(0)
            ->setMaxResults($this->maxResults)
            ->getQuery()
            ->getSingleColumnResult();

        return \count($ids);
    }

    public function getSlice(int $offset, int $length): iterable
    {
        return $this->inner->getSlice($offset, $length);
    }
}
