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
 * Read/write access to the package search index.
 *
 * All contact with the search vendor's client goes through implementations of this, so that a client
 * upgrade touches one class instead of every call site.
 *
 * The shapes below stay unsealed: the search API accepts far more parameters than we pass, and
 * records gain attributes over time, so only what the app actually relies on is spelled out.
 *
 * @phpstan-type PackageRecord array{
 *     id: int|string,
 *     objectID: string,
 *     name: string,
 *     package_organisation: string,
 *     package_name: string,
 *     description: string,
 *     type: string|null,
 *     repository: string,
 *     language: string|null,
 *     trendiness: float|int,
 *     popularity: float|int,
 *     abandoned: int,
 *     replacementPackage: string,
 *     tags: list<string>,
 *     meta?: array{downloads: int, downloads_formatted: string, favers: int, favers_formatted: string},
 *     extension?: int,
 *     extensionName?: string|null,
 *     ...<string, mixed>
 * }
 * @phpstan-type SearchParams array{
 *     query: string,
 *     hitsPerPage?: int,
 *     page?: int,
 *     filters?: string,
 *     facetFilters?: list<string>,
 *     numericFilters?: list<string>,
 *     ...<string, mixed>
 * }
 * @phpstan-type BrowseParams array{
 *     filters?: string,
 *     facetFilters?: list<string>,
 *     numericFilters?: list<string>,
 *     hitsPerPage?: int,
 *     ...<string, mixed>
 * }
 * @phpstan-type SearchResponse array{
 *     nbHits: int,
 *     page: int,
 *     nbPages: int,
 *     hits: list<array<string, mixed>>,
 *     ...<string, mixed>
 * }
 */
interface PackageIndex
{
    /**
     * @phpstan-param SearchParams $searchParams the request body
     *
     * @phpstan-return SearchResponse hits stay loose; callers narrow them to what they queried for
     *
     * @throws AlgoliaException          on a transport or API error
     * @throws \InvalidArgumentException on a malformed response body
     */
    public function search(array $searchParams): array;

    /**
     * Iterates the whole index with a cursor, unaffected by records being deleted while iterating.
     *
     * @phpstan-param BrowseParams $browseParams
     *
     * @phpstan-return iterable<PackageRecord>
     */
    public function browse(array $browseParams): iterable;

    /**
     * @phpstan-param list<PackageRecord> $records
     */
    public function saveRecords(array $records): void;

    public function deleteRecord(string $objectId): void;

    public function clear(): void;

    /**
     * @param array<string, mixed> $settings
     */
    public function updateSettings(array $settings): void;
}
