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

namespace App\Tests\Package\Git;

use Composer\Config;
use Composer\Downloader\MaxFileSizeExceededException;
use Composer\Downloader\TransportException;
use Composer\IO\NullIO;
use Composer\Util\Http\CurlResponse;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Symfony\Component\Process\Process;

/**
 * Serves requests to BASE_URL from bare repositories through a local `git http-backend`, so the real
 * server side of the protocol is exercised without any network access
 */
final class GitHttpBackendDownloader extends HttpDownloader
{
    public const string BASE_URL = 'https://8.8.8.8/';

    /** @var list<array{url: string, method: string, body: ?string, headers: list<string>, responseSize?: int}> */
    public array $requests = [];
    /** @var ?\Closure(string, string, ?string): ?Response return a response to short-circuit the backend */
    public ?\Closure $interceptor = null;
    /** @var array<string, string> URL prefix => prefix it redirects to */
    public array $redirects = [];

    /**
     * @param array<string, string> $serverConfig git config of the server, e.g. uploadpack.allowFilter
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly bool $protocolV2 = true,
        private readonly array $serverConfig = [],
    ) {
        parent::__construct(new NullIO(), new Config(false));
    }

    public function get($url, $options = []): Response
    {
        $method = $options['http']['method'] ?? 'GET';
        $body = $options['http']['content'] ?? null;
        $headers = $options['http']['header'] ?? [];
        $this->requests[] = ['url' => $url, 'method' => $method, 'body' => $body, 'headers' => $headers];

        foreach ($this->redirects as $from => $to) {
            if (str_starts_with($url, $from)) {
                $url = $to.substr($url, \strlen($from));
            }
        }

        $response = $this->interceptor !== null ? ($this->interceptor)($url, $method, $body) : null;
        $response ??= $this->runBackend($url, $method, $body, $headers);

        if ($response->getStatusCode() >= 400) {
            // mimics CurlDownloader's failure for error statuses
            $e = new TransportException('The "'.$url.'" file could not be downloaded (HTTP '.$response->getStatusCode().')', $response->getStatusCode());
            $e->setStatusCode($response->getStatusCode());
            $e->setResponse($response->getBody());
            throw $e;
        }
        $this->requests[\count($this->requests) - 1]['responseSize'] = \strlen((string) $response->getBody());
        if (isset($options['max_file_size']) && \strlen((string) $response->getBody()) > $options['max_file_size']) {
            throw new MaxFileSizeExceededException('Maximum allowed download size reached');
        }

        return $response;
    }

    public static function response(int $status, string $contentType, string $body, string $url = self::BASE_URL): CurlResponse
    {
        return new CurlResponse(['url' => $url], $status, ['Content-Type: '.$contentType], $body, ['url' => $url]);
    }

    /**
     * @param list<string> $headers
     */
    private function runBackend(string $url, string $method, ?string $body, array $headers): Response
    {
        if (!str_starts_with($url, self::BASE_URL)) {
            throw new \LogicException('Unexpected request to '.$url);
        }
        [$path, $query] = array_pad(explode('?', substr($url, \strlen(self::BASE_URL) - 1), 2), 2, '');

        $env = GitRepositoryBuilder::env() + [
            'GIT_PROJECT_ROOT' => $this->projectRoot,
            'GIT_HTTP_EXPORT_ALL' => '1',
            'PATH_INFO' => $path,
            'QUERY_STRING' => $query,
            'REQUEST_METHOD' => $method,
            'CONTENT_LENGTH' => (string) \strlen((string) $body),
            'GIT_CONFIG_COUNT' => (string) \count($this->serverConfig),
        ];
        foreach (array_keys($this->serverConfig) as $index => $key) {
            $env['GIT_CONFIG_KEY_'.$index] = $key;
            $env['GIT_CONFIG_VALUE_'.$index] = $this->serverConfig[$key];
        }
        foreach ($headers as $header) {
            [$name, $value] = array_map('trim', explode(':', $header, 2));
            if (strtolower($name) === 'content-type') {
                $env['CONTENT_TYPE'] = $value;
            } elseif (strtolower($name) === 'git-protocol' && $this->protocolV2) {
                $env['GIT_PROTOCOL'] = $value;
            }
        }

        // http-backend exits non-zero after sending an ERR packet, like a real server still answering the request
        $process = new Process(['git', 'http-backend'], $this->projectRoot, $env, $body);
        $process->run();
        if (!str_contains($process->getOutput(), "\r\n\r\n")) {
            throw new \RuntimeException('git http-backend failed: '.$process->getErrorOutput());
        }
        [$rawHeaders, $responseBody] = explode("\r\n\r\n", $process->getOutput(), 2);

        $status = 200;
        $responseHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (stripos($line, 'Status:') === 0) {
                $status = (int) trim(substr($line, 7));
            } else {
                $responseHeaders[] = $line;
            }
        }

        return new CurlResponse(['url' => $url], $status, $responseHeaders, $responseBody, ['url' => $url]);
    }
}
