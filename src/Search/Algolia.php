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

namespace App\Search;

use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;

/**
 * @phpstan-import-type SearchResult from ResultTransformer
 * @phpstan-import-type AlgoliaSearchResponse from ResultTransformer
 */
final class Algolia
{
    public function __construct(
        private PackageIndex $index,
        private ResultTransformer $transformer,
    ) {
    }

    /**
     * @phpstan-return SearchResult
     *
     * @throws AlgoliaException          on a transport or API error
     * @throws \InvalidArgumentException on a malformed response body
     */
    public function search(Query $query): array
    {
        /** @var AlgoliaSearchResponse $results */
        $results = $this->index->search($query->getSearchParams());

        return $this->transformer->transform($query, $results);
    }
}
