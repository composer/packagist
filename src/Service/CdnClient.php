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

use App\FilterList\Dump\FilterListSummaryDumper;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

class CdnClient
{
    public function __construct(
        private NoPrivateNetworkHttpClient $httpClient,
        private ?string $metadataEndpoint,
        private ?string $metadataPublicEndpoint,
        private ?string $metadataApiKey,
        private ?string $cdnApiKey,
        private string $packagistHost,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param non-empty-string $path
     *
     * @return int file modified time in units of 100-microseconds (i.e. 1.2345 seconds = a return value of 12345)
     */
    public function uploadMetadata(string $path, string $contents): int
    {
        $path = ltrim($path, '/');
        if ($this->metadataApiKey === null || $this->metadataEndpoint === null || $this->metadataPublicEndpoint === null || $this->cdnApiKey === null) {
            return (int) (time() * 10000);
        }

        $resp = $this->sendUploadMetadataRequest($path, $contents);
        if ($resp->getStatusCode() !== 201) {
            throw new \RuntimeException('Unexpected response from the CDN: '.$resp->getStatusCode().' '.$resp->getContent(false));
        }

        // fetch the file to get the last-modified timestamp reliably
        $resp = $this->httpClient->request('GET', $this->metadataEndpoint.$path, [
            'headers' => [
                'AccessKey' => $this->metadataApiKey,
            ],
        ]);
        $time = strtotime($resp->getHeaders()['last-modified'][0]) * 10000;

        return $time;
    }

    public function purgeMetadataCache(string $path): bool
    {
        if (!$this->purgeUrl($this->metadataPublicEndpoint.$path)) {
            return false;
        }

        if (!$this->purgeUrl('https://'.$this->packagistHost.'/'.$path)) {
            return false;
        }

        return true;
    }

    public function sendUploadMetadataRequest(string $path, string $contents): ResponseInterface
    {
        $hash = hash('sha256', $contents);

        return $this->httpClient->request('PUT', $this->metadataEndpoint.$path, [
            'headers' => [
                'AccessKey' => $this->metadataApiKey,
                'Content-Type' => 'application/json',
                'Checksum' => strtoupper($hash),
            ],
            'body' => $contents,
            'user_data' => ['path' => $path],
        ]);
    }

    public function deleteMetadata(string $path): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $resp = $this->httpClient->request('DELETE', $this->metadataEndpoint.$path, [
            'headers' => [
                'AccessKey' => $this->metadataApiKey,
            ],
        ]);

        // purge the cache as well
        $this->purgeMetadataCache($path);

        if (in_array($resp->getStatusCode(), [200, 404], true)) {
            return;
        }

        throw new \RuntimeException('Failed to delete metadata file '.$path.' ('.$resp->getStatusCode().' '.$resp->getContent(false).')');
    }

    /**
     * @param array<ResponseInterface> $requests
     */
    public function stream(array $requests): ResponseStreamInterface
    {
        return $this->httpClient->stream($requests);
    }

    public function isConfigured(): bool
    {
        return $this->metadataApiKey !== null && $this->metadataEndpoint !== null && $this->metadataPublicEndpoint !== null && $this->cdnApiKey !== null;
    }

    /**
     * Fetch file content from public CDN endpoint for verification
     */
    public function fetchPublicMetadata(string $path): string
    {
        $path = ltrim($path, '/');
        if (!$this->isConfigured()) {
            throw new \RuntimeException('CDN metadata public endpoint not configured');
        }

        $resp = $this->httpClient->request('GET', $this->metadataPublicEndpoint.$path);

        if ($resp->getStatusCode() !== 200) {
            throw new \RuntimeException('Failed to fetch public CDN file: '.$path.' (status: '.$resp->getStatusCode().')', $resp->getStatusCode());
        }

        return $resp->getContent();
    }

    public function purgeSummaryUrl(): bool
    {
        return $this->purgeUrl($this->metadataPublicEndpoint . FilterListSummaryDumper::SUMMARY_PATH);
    }

    public function wasPublicRepoFileModifiedSince(string $path, \DateTimeImmutable $since): bool
    {
        $resp = $this->httpClient->request('HEAD', $this->metadataPublicEndpoint . $path, [
            'headers' => [
                'If-Modified-Since' => $since->format(\DateTimeInterface::RFC7231),
            ],
        ]);

        return $resp->getStatusCode() === 200;
    }

    public function purgeUrl(string $url): bool
    {
        if (!$this->isConfigured()) {
            return true;
        }

        if (str_ends_with($url, '*')) {
            $resp = $this->httpClient->request('POST', 'https://api.bunny.net/purge?'.http_build_query(['url' => $url, 'async' => 'true']), [
                'headers' => [
                    'AccessKey' => $this->cdnApiKey,
                ],
            ]);
        } else {
            $resp = $this->httpClient->request('POST', 'https://api.bunny.net/purge?'.http_build_query(['url' => $url, 'async' => 'false', 'exactPath' => 'true']), [
                'headers' => [
                    'AccessKey' => $this->cdnApiKey,
                ],
            ]);
        }

        // delay the response to slow things down when we're hitting the CDN too hard and get rate limited
        if ($resp->getStatusCode() === 429) {
            sleep(1);

            $this->logger->warning('CDN rate limit hit while purging '.$url.', slowing down', ['status' => $resp->getStatusCode()]);

            return false;
        }

        // wait for status code at least
        if ($resp->getStatusCode() !== 200) {
            $this->logger->error('Failed purging '.$url.' from metadata CDN', ['response' => $resp->getContent(false), 'status' => $resp->getStatusCode()]);

            return false;
        }

        return true;
    }
}
