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

namespace App\Socket;

use App\Util\Rfc3339;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @see https://docs.socket.dev/reference/getorgthreatfeeditems
 */
class SocketThreatFeed
{
    private const string FEED_URL = 'https://api.socket.dev/v0/orgs/%s/threat-feed';
    private const int PER_PAGE = 100;
    private const int MAX_PAGES = 1000;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire(env: 'SOCKET_API_KEY')]
        private string $apiKey,
        #[Autowire(env: 'SOCKET_ORG_SLUG')]
        private string $orgSlug,
    ) {
    }

    /**
     * Fetches every Composer threat matching the feed filter (e.g. "mal" or "typo").
     *
     * Callers replace what they stored from a previous run with the result, so a truncated
     * feed fails the whole fetch (null) instead of returning what was fetched so far.
     *
     * @return list<SocketThreat>|null
     */
    public function getThreats(string $filter): ?array
    {
        if ($this->apiKey === '' || $this->orgSlug === '') {
            $this->logger->info('Socket threat feed is not configured, set SOCKET_API_KEY and SOCKET_ORG_SLUG');

            return null;
        }

        $threats = [];
        $cursor = null;
        $page = 0;
        try {
            do {
                if (++$page > self::MAX_PAGES) {
                    throw new \RuntimeException('Socket threat feed exceeded '.self::MAX_PAGES.' pages');
                }

                $data = json_decode(
                    $this->httpClient->request('GET', \sprintf(self::FEED_URL, rawurlencode($this->orgSlug)), [
                        'auth_bearer' => $this->apiKey,
                        'query' => array_filter([
                            'ecosystem' => 'composer',
                            'filter' => $filter,
                            'per_page' => self::PER_PAGE,
                            'sort' => 'id',
                            'direction' => 'asc',
                            'startAfterCursor' => $cursor,
                        ], static fn ($value) => $value !== null),
                    ])->getContent(),
                    true,
                    flags: \JSON_THROW_ON_ERROR,
                );

                foreach ($data['results'] as $item) {
                    $package = $this->parsePurl($item['purl']);
                    if ($package === null) {
                        continue;
                    }

                    $threats[] = new SocketThreat(
                        $package[0],
                        $package[1],
                        $item['threatType'],
                        $item['needsHumanReview'],
                        $this->getLink($item),
                        Rfc3339::parse($item['createdAt'] ?? null),
                    );
                }

                $cursor = $data['endCursor'] ?? null;
            } while ($cursor !== null && $data['results'] !== []);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch Socket threat feed', [
                'filter' => $filter,
                'exception' => $e,
            ]);

            return null;
        }

        return $threats;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function getLink(array $item): ?string
    {
        // locationHtmlUrl includes the flagged file's path, so it can outgrow the link column
        foreach ([$item['locationHtmlUrl'] ?? null, $item['packageHtmlUrl'] ?? null] as $url) {
            if (\is_string($url) && str_starts_with($url, 'https://socket.dev/') && \strlen($url) <= 2048) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @return array{string, string}|null package name and version
     */
    private function parsePurl(string $purl): ?array
    {
        if (!preg_match('{^pkg:composer/([^/@?#]+)/([^/@?#]+)@([^?#]+)}i', $purl, $match)) {
            return null;
        }

        return [strtolower(rawurldecode($match[1]).'/'.rawurldecode($match[2])), rawurldecode($match[3])];
    }
}
