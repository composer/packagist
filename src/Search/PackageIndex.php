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
 */
interface PackageIndex
{
    /**
     * @param array<string, mixed> $searchParams the request body: query, filters, hitsPerPage, page, ...
     *
     * @return array<string, mixed> the raw search response; callers narrow it to the shape they asked for
     *
     * @throws AlgoliaException          on a transport or API error
     * @throws \InvalidArgumentException on a malformed response body
     */
    public function search(array $searchParams): array;

    /**
     * Iterates the whole index with a cursor, unaffected by records being deleted while iterating.
     *
     * @param array<string, mixed> $browseParams
     *
     * @return iterable<array<string, mixed>>
     */
    public function browse(array $browseParams): iterable;

    /**
     * @param list<array<string, mixed>> $records each must carry an objectID
     */
    public function saveRecords(array $records): void;

    public function deleteRecord(string $objectId): void;

    public function clear(): void;

    /**
     * @param array<string, mixed> $settings
     */
    public function updateSettings(array $settings): void;
}
