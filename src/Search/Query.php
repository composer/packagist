<?php declare(strict_types=1);

namespace App\Search;

final class Query
{
    public function __construct(
        public string $query,
        /** @var string|list<string> */
        public array|string $tags,
        public string $type,
        public int $perPage,
        public int $page,
    ) {
        $this->type = str_replace('%type%', '', $type);
        $this->perPage =  max(1, $perPage);
        $this->page =  max(1, $page) - 1;

        if (!$this->query && !$this->type && !$this->tags) {
            throw new \InvalidArgumentException('Missing search query, example: ?q=example');
        }

        if ($perPage > 100) {
            throw new \InvalidArgumentException('The optional packages per_page parameter must be an integer between 1 and 100 (default: 15)');
        }
    }

    /**
     * @return array{hitsPerPage: int, page: int, filters?: string}
     */
    public function getOptions(): array
    {
        $queryParams = [
            'hitsPerPage' => $this->perPage,
            'page' => $this->page,
        ];

        $filters = [];

        if ($this->type) {
            $filters[] = 'type:'.$this->type;
        }

        // filter by tags
        if ($this->tags) {
            $tags = [];
            foreach ((array) $this->tags as $tag) {
                $tag = strtr($tag, '-', ' ');
                $tags[] = 'tags:"'.$tag.'"';
                if (str_contains($tag, ' ')) {
                    $tags[] = 'tags:"'.strtr($tag, ' ', '-').'"';
                }
            }
            $filters[] = '(' . implode(' OR ', $tags) . ')';
        }

        if (0 !== count($filters)) {
            $queryParams['filters'] = implode(' AND ', $filters);
        }

        return $queryParams;
    }
}
