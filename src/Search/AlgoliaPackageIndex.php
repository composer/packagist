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

use Algolia\AlgoliaSearch\Api\SearchClient;
use Algolia\AlgoliaSearch\Exceptions\MissingObjectId;

/**
 * @phpstan-import-type PackageRecord from PackageIndex
 * @phpstan-import-type SearchParams from PackageIndex
 * @phpstan-import-type BrowseParams from PackageIndex
 * @phpstan-import-type SearchResponse from PackageIndex
 */
final class AlgoliaPackageIndex implements PackageIndex
{
    public function __construct(
        private SearchClient $algolia,
        private string $algoliaIndexName,
    ) {
    }

    /**
     * @phpstan-param SearchParams $searchParams
     *
     * @phpstan-return SearchResponse
     */
    public function search(array $searchParams): array
    {
        // $searchParams is the request body. Anything passed as the client's third argument instead
        // is silently dropped (RequestOptions only reads headers/queryParameters/body/timeouts), so
        // filters and pagination must never be moved there.
        /** @var SearchResponse $result */
        $result = $this->algolia->searchSingleIndex($this->algoliaIndexName, $searchParams);

        return $result;
    }

    /**
     * @phpstan-param BrowseParams $browseParams
     *
     * @phpstan-return iterable<PackageRecord>
     */
    public function browse(array $browseParams): iterable
    {
        /** @var iterable<PackageRecord> $records */
        $records = $this->algolia->browseObjects($this->algoliaIndexName, $browseParams);

        return $records;
    }

    /**
     * @phpstan-param list<PackageRecord> $records
     */
    public function saveRecords(array $records): void
    {
        // v3 rejected records without an objectID client-side; v4's saveObjects() would instead let
        // Algolia generate one, creating a record we could never update or delete again.
        foreach ($records as $record) {
            if (!isset($record['objectID']) || $record['objectID'] === '') {
                throw new MissingObjectId('All records must have an objectID to be indexed.');
            }
        }

        $this->algolia->saveObjects($this->algoliaIndexName, $records);
    }

    public function deleteRecord(string $objectId): void
    {
        $this->algolia->deleteObject($this->algoliaIndexName, $objectId);
    }

    public function clear(): void
    {
        $this->algolia->clearObjects($this->algoliaIndexName);
    }

    public function updateSettings(array $settings): void
    {
        $this->algolia->setSettings($this->algoliaIndexName, $settings);
    }
}
