<?php declare(strict_types=1);

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
