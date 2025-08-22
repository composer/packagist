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

use Composer\Pcre\Preg;
use Symfony\Component\String\UnicodeString;

/**
 * @phpstan-type SearchOptions array{hitsPerPage: int, page: int, filters?: string}
 */
final class Query
{
    public function __construct(
        public string $query,
        /** @var list<string> */
        public array $tags,
        public string $type,
        public int $perPage,
        public int $page,
    ) {
        $this->query = Preg::replace('{([^\s])-}', '$1--', (string) new UnicodeString($query));
        $this->type = str_replace('%type%', '', (string) new UnicodeString($type));
        $this->perPage = max(1, $perPage);
        $this->page = max(1, $page) - 1;

        // validate tags
        foreach ($tags as $tag) {
            new UnicodeString($tag);
        }

        if ('' === $this->query && '' === $this->type && count($this->tags) === 0) {
            throw new \InvalidArgumentException('Missing search query, example: ?q=example');
        }

        if ($perPage > 100) {
            throw new \InvalidArgumentException('The optional packages per_page parameter must be an integer between 1 and 100 (default: 15)');
        }
    }

    /**
     * @phpstan-return SearchOptions
     */
    public function getOptions(): array
    {
        $queryParams = [
            'hitsPerPage' => $this->perPage,
            'page' => $this->page,
        ];

        $filters = [];

        if ($this->type) {
            $filters[] = 'type:"'.Preg::replace('{\\\\*"}', '\"', $this->type).'"';
        }

        // filter by tags
        if ($this->tags) {
            $tags = [];
            foreach ($this->tags as $tag) {
                $tag = Preg::replace('{[\s-]+}u', ' ', mb_strtolower(Preg::replace('{[\x00-\x1f]+}u', '', $tag), 'UTF-8'));
                $tag = Preg::replace('{\\\\*"}', '\"', $tag);
                $tags[] = 'tags:"'.$tag.'"';
                if (str_contains($tag, ' ')) {
                    $tags[] = 'tags:"'.strtr($tag, ' ', '-').'"';
                }
            }
            $filters[] = '('.implode(' OR ', $tags).')';
        }

        if (0 !== count($filters)) {
            $queryParams['filters'] = implode(' AND ', $filters);
        }

        return $queryParams;
    }
}
