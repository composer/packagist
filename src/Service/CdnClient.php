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
        if (!$this->isConfigured()) {
            return true;
        }

        $resp = $this->httpClient->request('POST', 'https://api.bunny.net/purge?'.http_build_query(['url' => $this->metadataPublicEndpoint.$path, 'async' => 'false']), [
            'headers' => [
                'AccessKey' => $this->cdnApiKey,
            ],
        ]);
        // wait for status code at least
        if ($resp->getStatusCode() !== 200) {
            $this->logger->error('Failed purging '.$path.' from metadata CDN', ['response' => $resp->getContent(false), 'status' => $resp->getStatusCode()]);

            return false;
        }

        $resp = $this->httpClient->request('POST', 'https://api.bunny.net/purge?'.http_build_query(['url' => 'https://'.$this->packagistHost.'/'.$path, 'async' => 'false']), [
            'headers' => [
                'AccessKey' => $this->cdnApiKey,
            ],
        ]);
        // wait for status code at least
        if ($resp->getStatusCode() !== 200) {
            $this->logger->error('Failed purging '.$path.' from main host CDN', ['response' => $resp->getContent(false), 'status' => $resp->getStatusCode()]);

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

        if ($resp->getStatusCode() === 200) {
            // purge the cache as well if the file was deleted
            $resp = $this->httpClient->request('POST', 'https://api.bunny.net/purge?'.http_build_query(['url' => $this->metadataPublicEndpoint.$path, 'async' => 'true']), [
                'headers' => [
                    'AccessKey' => $this->cdnApiKey,
                ],
            ]);
            // wait for status code at least
            $resp->getStatusCode();

            return;
        }

        if ($resp->getStatusCode() === 404) {
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
}
