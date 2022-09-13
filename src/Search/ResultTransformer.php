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

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @phpstan-type SearchResult array{
 *     total: int,
 *     next?: string,
 *     results: array<array{
 *         name: string,
 *         description: string,
 *         url: string,
 *         repository: string,
 *         downloads?: int,
 *         favers?: int,
 *         virtual?: bool,
 *         abandoned?: true|string
 *     }>
 * }
 */
final class ResultTransformer
{
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * @param array{
     *     nbHits: int,
     *     page: int,
     *     nbPages: int,
     *     hits: array<array{
     *         id: int,
     *         name: string,
     *         description: string,
     *         repository: string,
     *         meta: array{downloads: int, favers: int},
     *         abandoned?: bool,
     *         replacementPackage?: string
     *     }>
     * } $results
     *
     * @phpstan-return SearchResult
     */
    public function transform(Query $query, array $results): array
    {
        $result = [
            'results' => [],
            'total' => $results['nbHits'],
        ];

        foreach ($results['hits'] as $package) {
            if (ctype_digit((string) $package['id'])) {
                $url = $this->urlGenerator->generate('view_package', ['name' => $package['name']], UrlGeneratorInterface::ABSOLUTE_URL);
            } else {
                $url = $this->urlGenerator->generate('view_providers', ['name' => $package['name']], UrlGeneratorInterface::ABSOLUTE_URL);
            }

            $row = [
                'name' => $package['name'],
                'description' => $package['description'] ?: '',
                'url' => $url,
                'repository' => $package['repository'],
            ];
            if (ctype_digit((string) $package['id'])) {
                $row['downloads'] = $package['meta']['downloads'];
                $row['favers'] = $package['meta']['favers'];
            } else {
                $row['virtual'] = true;
            }
            if (!empty($package['abandoned'])) {
                $row['abandoned'] = isset($package['replacementPackage']) && $package['replacementPackage'] !== '' ? $package['replacementPackage'] : true;
            }
            $result['results'][] = $row;
        }

        if ($results['nbPages'] > $results['page'] + 1) {
            $params = [
                'q' => $query->query,
                'page' => $results['page'] + 2,
            ];
            if ($query->tags) {
                $params['tags'] = $query->tags;
            }
            if ($query->type) {
                $params['type'] = $query->type;
            }
            if ($query->perPage !== 15) {
                $params['per_page'] = $query->perPage;
            }
            $result['next'] = $this->urlGenerator->generate('search_api', $params, UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return $result;
    }
}
