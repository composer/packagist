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

namespace App\Tests\Socket;

use App\Socket\SocketThreat;
use App\Socket\SocketThreatFeed;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SocketThreatFeedTest extends TestCase
{
    public function testGetThreatsReturnsThreats(): void
    {
        $httpClient = new MockHttpClient([$this->createPage([
            $this->createItem('pkg:composer/vendor/malware@1.0.0'),
            $this->createItem('pkg:composer/vendor/possible@2.0.0', 'possible_malware', needsHumanReview: true),
        ])]);
        $feed = new SocketThreatFeed($httpClient, new NullLogger(), 'api-key', 'org');

        $result = $feed->getThreats('mal');

        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $reportedAt = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        $this->assertEquals(new SocketThreat('vendor/malware', '1.0.0', 'malware', false, 'https://socket.dev/composer/package/vendor/malware/alerts/1.0.0', $reportedAt), $result[0]);
        $this->assertEquals(new SocketThreat('vendor/possible', '2.0.0', 'possible_malware', true, 'https://socket.dev/composer/package/vendor/possible/alerts/2.0.0', $reportedAt), $result[1]);
    }

    public function testGetThreatsReportsCreatedAtInUtc(): void
    {
        $item = $this->createItem('pkg:composer/vendor/malware@1.0.0');
        $item['createdAt'] = '2026-01-01T02:30:00+02:00';
        $feed = new SocketThreatFeed(new MockHttpClient([$this->createPage([$item])]), new NullLogger(), 'api-key', 'org');

        $result = $feed->getThreats('mal');

        $this->assertNotNull($result);
        $this->assertSame('2026-01-01 00:30:00 UTC', $result[0]->reportedAt?->format('Y-m-d H:i:s T'));
    }

    public function testGetThreatsLeavesReportedAtEmptyWhenCreatedAtIsUnusable(): void
    {
        $invalid = $this->createItem('pkg:composer/vendor/invalid@1.0.0');
        $invalid['createdAt'] = 'not a date';
        $missing = $this->createItem('pkg:composer/vendor/missing@1.0.0');
        unset($missing['createdAt']);
        $feed = new SocketThreatFeed(new MockHttpClient([$this->createPage([$invalid, $missing])]), new NullLogger(), 'api-key', 'org');

        $result = $feed->getThreats('mal');

        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $this->assertNull($result[0]->reportedAt);
        $this->assertNull($result[1]->reportedAt);
    }

    public function testGetThreatsQueriesComposerFeedWithFilter(): void
    {
        $requests = [];
        $httpClient = $this->createRecordingHttpClient([$this->createPage([])], $requests);
        $feed = new SocketThreatFeed($httpClient, new NullLogger(), 'api-key', 'my org');

        $feed->getThreats('typo');

        $this->assertCount(1, $requests);
        $url = parse_url($requests[0]['url']);
        $this->assertSame('api.socket.dev', $url['host']);
        $this->assertSame('/v0/orgs/my%20org/threat-feed', $url['path']);
        parse_str($url['query'], $query);
        $this->assertSame('composer', $query['ecosystem']);
        $this->assertSame('typo', $query['filter']);
        $this->assertArrayNotHasKey('startAfterCursor', $query);
        $this->assertContains('Authorization: Bearer api-key', $requests[0]['options']['headers']);
    }

    public function testGetThreatsFollowsCursor(): void
    {
        $requests = [];
        $httpClient = $this->createRecordingHttpClient([
            $this->createPage([$this->createItem('pkg:composer/vendor/first@1.0.0')], 'cursor-1'),
            $this->createPage([$this->createItem('pkg:composer/vendor/second@1.0.0')], 'cursor-2'),
            $this->createPage([]),
        ], $requests);
        $feed = new SocketThreatFeed($httpClient, new NullLogger(), 'api-key', 'org');

        $result = $feed->getThreats('mal');

        $this->assertCount(3, $requests);
        parse_str((string) parse_url($requests[1]['url'], \PHP_URL_QUERY), $query);
        $this->assertSame('cursor-1', $query['startAfterCursor']);
        parse_str((string) parse_url($requests[2]['url'], \PHP_URL_QUERY), $query);
        $this->assertSame('cursor-2', $query['startAfterCursor']);
        $this->assertNotNull($result);
        $this->assertSame(['vendor/first', 'vendor/second'], array_map(static fn (SocketThreat $threat) => $threat->packageName, $result));
    }

    public function testGetThreatsStopsOnEmptyPage(): void
    {
        $httpClient = new MockHttpClient([
            $this->createPage([$this->createItem('pkg:composer/vendor/malware@1.0.0')], 'cursor-1'),
            $this->createPage([], 'cursor-2'),
        ]);
        $feed = new SocketThreatFeed($httpClient, new NullLogger(), 'api-key', 'org');

        $result = $feed->getThreats('mal');

        $this->assertSame(2, $httpClient->getRequestsCount());
        $this->assertNotNull($result);
        $this->assertCount(1, $result);
    }

    public function testGetThreatsReturnsNullWhenALaterPageFails(): void
    {
        $httpClient = new MockHttpClient([
            $this->createPage([$this->createItem('pkg:composer/vendor/malware@1.0.0')], 'cursor-1'),
            new MockResponse('{"error":{"message":"Insufficient quota"}}', ['http_code' => 429]),
        ]);
        $feed = new SocketThreatFeed($httpClient, new NullLogger(), 'api-key', 'org');

        $this->assertNull($feed->getThreats('mal'));
    }

    public function testGetThreatsParsesPurl(): void
    {
        $httpClient = new MockHttpClient([$this->createPage([
            $this->createItem('pkg:composer/Vendor/Package@v1.2.3-beta'),
            $this->createItem('pkg:composer/vendor/encoded@1.0.0%2Bbuild?repository_url=https://example.org#src'),
            $this->createItem('pkg:composer/vendor/no-version'),
            $this->createItem('pkg:composer/no-vendor@1.0.0'),
            $this->createItem('pkg:npm/vendor/package@1.0.0'),
        ])]);
        $feed = new SocketThreatFeed($httpClient, new NullLogger(), 'api-key', 'org');

        $result = $feed->getThreats('mal');

        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $this->assertSame('vendor/package', $result[0]->packageName);
        $this->assertSame('v1.2.3-beta', $result[0]->version);
        $this->assertSame('vendor/encoded', $result[1]->packageName);
        $this->assertSame('1.0.0+build', $result[1]->version);
    }

    public function testGetThreatsKeepsLongThreatLink(): void
    {
        $link = 'https://socket.dev/composer/package/vendor/malware/files/1.0.0/'.str_repeat('a/', 120).'evil.php';
        $httpClient = new MockHttpClient([$this->createPage([
            $this->createItem('pkg:composer/vendor/malware@1.0.0', locationHtmlUrl: $link),
        ])]);
        $feed = new SocketThreatFeed($httpClient, new NullLogger(), 'api-key', 'org');

        $result = $feed->getThreats('mal');

        $this->assertNotNull($result);
        $this->assertGreaterThan(255, \strlen($link));
        $this->assertSame($link, $result[0]->link);
    }

    public function testGetThreatsUsesThreatLink(): void
    {
        $httpClient = new MockHttpClient([$this->createPage([
            $this->createItem('pkg:composer/vendor/malware@1.0.0', locationHtmlUrl: 'https://socket.dev/composer/package/vendor/malware/files/1.0.0/src/evil.php'),
        ])]);
        $feed = new SocketThreatFeed($httpClient, new NullLogger(), 'api-key', 'org');

        $result = $feed->getThreats('mal');

        $this->assertNotNull($result);
        $this->assertSame('https://socket.dev/composer/package/vendor/malware/files/1.0.0/src/evil.php', $result[0]->link);
    }

    public function testGetThreatsFallsBackToPackageLinkWhenThreatLinkIsTooLong(): void
    {
        $item = $this->createItem('pkg:composer/vendor/malware@1.0.0', locationHtmlUrl: 'https://socket.dev/composer/package/vendor/malware/files/1.0.0/'.str_repeat('a/', 1020).'evil.php');
        $item['packageHtmlUrl'] = 'https://socket.dev/composer/package/vendor/malware';
        $feed = new SocketThreatFeed(new MockHttpClient([$this->createPage([$item])]), new NullLogger(), 'api-key', 'org');

        $result = $feed->getThreats('mal');

        $this->assertNotNull($result);
        $this->assertSame('https://socket.dev/composer/package/vendor/malware', $result[0]->link);
    }

    public function testGetThreatsDropsLinksOutsideSocket(): void
    {
        $item = $this->createItem('pkg:composer/vendor/malware@1.0.0', locationHtmlUrl: 'javascript:alert(1)');
        $item['packageHtmlUrl'] = 'https://example.org/vendor/malware';
        $feed = new SocketThreatFeed(new MockHttpClient([$this->createPage([$item])]), new NullLogger(), 'api-key', 'org');

        $result = $feed->getThreats('mal');

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertNull($result[0]->link);
    }

    public function testGetThreatsReturnsNullWhenFeedNeverEnds(): void
    {
        $requests = 0;
        $httpClient = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return $this->createPage([$this->createItem('pkg:composer/vendor/malware@'.$requests.'.0.0')], 'cursor-'.$requests);
        });
        $feed = new SocketThreatFeed($httpClient, new NullLogger(), 'api-key', 'org');

        $this->assertNull($feed->getThreats('mal'));
        $this->assertSame(1000, $requests);
    }

    public function testGetThreatsReturnsNullWhenNotConfigured(): void
    {
        $httpClient = new MockHttpClient([]);

        $this->assertNull(new SocketThreatFeed($httpClient, new NullLogger(), '', 'org')->getThreats('mal'));
        $this->assertNull(new SocketThreatFeed($httpClient, new NullLogger(), 'api-key', '')->getThreats('mal'));
        $this->assertSame(0, $httpClient->getRequestsCount());
    }

    public function testGetThreatsReturnsNullOnHttpError(): void
    {
        $feed = new SocketThreatFeed(new MockHttpClient([new TransportException()]), new NullLogger(), 'api-key', 'org');

        $this->assertNull($feed->getThreats('mal'));
    }

    public function testGetThreatsReturnsNullOnUnauthorized(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('{"error":{"message":"Unauthorized"}}', ['http_code' => 401])]);
        $feed = new SocketThreatFeed($httpClient, new NullLogger(), 'api-key', 'org');

        $this->assertNull($feed->getThreats('mal'));
    }

    public function testGetThreatsReturnsNullOnInvalidJson(): void
    {
        $feed = new SocketThreatFeed(new MockHttpClient([new MockResponse('not valid json')]), new NullLogger(), 'api-key', 'org');

        $this->assertNull($feed->getThreats('mal'));
    }

    public function testGetThreatsEmptyFeed(): void
    {
        $feed = new SocketThreatFeed(new MockHttpClient([$this->createPage([])]), new NullLogger(), 'api-key', 'org');

        $this->assertSame([], $feed->getThreats('mal'));
    }

    /**
     * @param list<MockResponse>                                      $responses
     * @param list<array{url: string, options: array<string, mixed>}> $requests
     */
    private function createRecordingHttpClient(array $responses, array &$requests): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$requests): MockResponse {
            $requests[] = ['url' => $url, 'options' => $options];

            return array_shift($responses) ?? throw new \LogicException('Unexpected request to '.$url);
        });
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function createPage(array $results, ?string $endCursor = null): MockResponse
    {
        return new MockResponse(json_encode(['results' => $results, 'endCursor' => $endCursor], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function createItem(string $purl, string $threatType = 'malware', bool $needsHumanReview = false, ?string $locationHtmlUrl = null): array
    {
        $path = preg_replace('{^pkg:composer/([^@]+)@(.*)$}', '$1/alerts/$2', $purl);

        return [
            'id' => 1,
            'threatInstanceId' => 1,
            'purl' => $purl,
            'threatType' => $threatType,
            'description' => 'Malicious code',
            'needsHumanReview' => $needsHumanReview,
            'fileSha256' => null,
            'createdAt' => '2026-01-01T00:00:00.000Z',
            'updatedAt' => '2026-01-01T00:00:00.000Z',
            'publishedAt' => null,
            'removedAt' => null,
            'locationHtmlUrl' => $locationHtmlUrl ?? 'https://socket.dev/composer/package/'.$path,
            'packageHtmlUrl' => 'https://socket.dev/composer/package/'.$path,
        ];
    }
}
