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

namespace App\Service;

use Composer\Pcre\Preg;
use Psr\Log\LoggerInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BlogRssFetcher
{
    private const string RSS_URL = 'https://blog.packagist.com/rss/';
    private const int CACHE_TTL = 3600;
    private const int MAX_ITEMS = 5;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{title: string, link: string, description: string, datePublished: \DateTimeInterface|null}>
     */
    public function getNewsItems(): array
    {
        try {
            return $this->cache->get('blog_news_items', function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->fetchAndParseRss();
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch blog RSS feed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return list<array{title: string, link: string, description: string, datePublished: \DateTimeInterface|null}>
     */
    private function fetchAndParseRss(): array
    {
        $response = $this->httpClient->request('GET', self::RSS_URL);
        $content = $response->getContent();

        $xml = new \SimpleXMLElement($content);
        $items = [];

        foreach ($xml->channel->item as $entry) {
            // Extract categories
            $categories = [];
            foreach ($entry->category as $category) {
                $categories[] = strtolower((string) $category);
            }

            // Only include items with composer or packagist.org category
            if (!in_array('composer', $categories, true) && !in_array('packagist.org', $categories, true)) {
                continue;
            }

            $pubDate = null;
            if (isset($entry->pubDate)) {
                try {
                    $pubDate = new \DateTimeImmutable((string) $entry->pubDate);
                } catch (\Exception) {
                    // Ignore invalid dates
                }
            }

            $desc = $entry->description;
            $desc = str_replace('><', '> <', (string) $desc);
            $desc = trim(html_entity_decode(strip_tags($desc), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            $items[] = [
                'title' => (string) $entry->title,
                'link' => (string) $entry->link,
                'description' => $desc,
                'datePublished' => $pubDate,
            ];

            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $items;
    }
}
