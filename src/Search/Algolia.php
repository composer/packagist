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
use Algolia\AlgoliaSearch\SearchClient;

/**
 * @phpstan-import-type SearchResult from ResultTransformer
 */
final class Algolia
{
    public function __construct(
        private SearchClient $algolia,
        private string $algoliaIndexName,
        private ResultTransformer $transformer,
    ) {
    }

    /**
     * @phpstan-return SearchResult
     *
     * @throws AlgoliaException
     */
    public function search(Query $query): array
    {
        $index = $this->algolia->initIndex($this->algoliaIndexName);

        return $this->transformer->transform(
            $query,
            $index->search($query->query, $query->getOptions())
        );
    }
}
