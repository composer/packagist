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

namespace App\Tests\Service;

use App\Service\BlogRssFetcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class BlogRssFetcherTest extends TestCase
{
    public function testItemWithoutPubDateHasNoDatePublished(): void
    {
        $items = $this->fetch(<<<XML
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <item>
                        <title>Dated post</title>
                        <link>https://blog.packagist.com/dated/</link>
                        <description>&lt;p&gt;Has a pubDate&lt;/p&gt;</description>
                        <category>composer</category>
                        <pubDate>Tue, 02 Sep 2025 10:00:00 +0000</pubDate>
                    </item>
                    <item>
                        <title>Undated post</title>
                        <link>https://blog.packagist.com/undated/</link>
                        <description>Has no pubDate</description>
                        <category>composer</category>
                    </item>
                </channel>
            </rss>
            XML);

        self::assertCount(2, $items);
        self::assertSame('2025-09-02', $items[0]['datePublished']?->format('Y-m-d'));
        self::assertSame('Has a pubDate', $items[0]['description']);

        // templates have to guard this, twig's date filter renders null as today
        self::assertNull($items[1]['datePublished']);
    }

    public function testUnrelatedCategoriesAreSkipped(): void
    {
        $items = $this->fetch(<<<XML
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <item>
                        <title>Private Packagist news</title>
                        <link>https://blog.packagist.com/private/</link>
                        <description>Not for this site</description>
                        <category>private-packagist</category>
                        <pubDate>Tue, 02 Sep 2025 10:00:00 +0000</pubDate>
                    </item>
                </channel>
            </rss>
            XML);

        self::assertSame([], $items);
    }

    public function testFailedFetchReturnsNoItems(): void
    {
        self::assertSame([], $this->fetch('nope', 500));
    }

    /**
     * @return list<array{title: string, link: string, description: string, datePublished: \DateTimeInterface|null}>
     */
    private function fetch(string $body, int $statusCode = 200): array
    {
        $httpClient = new MockHttpClient(new MockResponse($body, ['http_code' => $statusCode]));

        return (new BlogRssFetcher($httpClient, new ArrayAdapter(), new NullLogger()))->getNewsItems();
    }
}
