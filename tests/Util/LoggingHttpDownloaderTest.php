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

namespace App\Tests\Util;

use App\Util\LoggingHttpDownloader;
use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\BufferIO;
use Composer\Util\Http\Response;
use Graze\DogStatsD\Client as StatsDClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class LoggingHttpDownloaderTest extends TestCase
{
    private const REPO_URL = 'https://api.github.com/repos/acme/lib';

    /**
     * @return iterable<string, array{int, list<string>, string, string}>
     */
    public static function failureProvider(): iterable
    {
        yield 'revoked token' => [401, [], '', 'failed with HTTP 401 (token rejected)'];
        yield 'saml sso' => [403, ['X-GitHub-SSO: required; url=https://github.com/orgs/acme/sso?authorization_request=SECRET'], '{"message":"Resource protected by organization SAML enforcement. https://github.com/orgs/acme/sso?authorization_request=SECRET"}', '(token not authorized for the organization\'s SAML SSO)'];
        yield 'rate limit' => [403, ['x-ratelimit-remaining: 0'], '', '(rate limit exhausted)'];
        yield 'ip allow list' => [403, [], '{"message":"Although you appear to have the correct authorization credentials, the `acme` organization has an IP allow list enabled"}', '(organization IP allow list blocks the token)'];
        yield 'unknown repo data failure' => [404, [], '{"message":"Not Found"}', 'failed with HTTP 404 using'];
    }

    /**
     * @param list<string> $headers
     */
    #[DataProvider('failureProvider')]
    public function testReportsGitHubApiFailureWithoutLeakingResponse(int $status, array $headers, string $body, string $expected): void
    {
        $io = new BufferIO();
        $downloader = $this->createDownloader($io, false, static fn () => null, [self::createException($status, $headers, $body)]);

        try {
            $downloader->get(self::REPO_URL);
            $this->fail('Expected the TransportException to be rethrown');
        } catch (TransportException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }

        $output = $io->getOutput();
        $this->assertStringContainsString('GitHub API request to '.self::REPO_URL.' failed', $output);
        $this->assertStringContainsString($expected, $output);
        $this->assertStringContainsString('using a maintainer\'s token', $output);
        $this->assertStringNotContainsString('SECRET', $output);
    }

    public function testStripsQueryString(): void
    {
        $io = new BufferIO();
        $downloader = $this->createDownloader($io, false, static fn () => null, [self::createException(403, ['x-ratelimit-remaining: 0'])]);

        try {
            $downloader->get(self::REPO_URL.'/git/refs/heads?per_page=100&page=2');
            $this->fail('Expected the TransportException to be rethrown');
        } catch (TransportException) {
        }

        $this->assertStringContainsString(self::REPO_URL.'/git/refs/heads failed', $io->getOutput());
        $this->assertStringNotContainsString('per_page', $io->getOutput());
    }

    #[TestWith([401])]
    #[TestWith([403])]
    #[TestWith([404])]
    public function testRetriesRefusedRepoDataWithPackagistToken(int $status): void
    {
        $io = new BufferIO();
        $io->setAuthentication('github.com', 'maintainer-token', 'x-oauth-basic');
        $downloader = $this->createDownloader($io, false, static fn () => 'packagist-token', [
            self::createException($status),
            new Response(['url' => self::REPO_URL], 200, [], '{"name":"lib"}'),
        ]);

        $this->assertSame('{"name":"lib"}', $downloader->get(self::REPO_URL)->getBody());
        $this->assertSame([self::REPO_URL, self::REPO_URL], $downloader->requested);
        $this->assertSame('packagist-token', $io->getAuthentication('github.com')['username']);
        $this->assertMatchesRegularExpression('{failed with HTTP '.$status.'.*using a maintainer\'s token.*\n.*Retrying with the Packagist token}', $io->getOutput());
    }

    public function testRetriesOnlyOnce(): void
    {
        $io = new BufferIO();
        $downloader = $this->createDownloader($io, false, static fn () => 'packagist-token', [
            self::createException(404),
            self::createException(404),
        ]);

        try {
            $downloader->get(self::REPO_URL);
            $this->fail('Expected the TransportException to be rethrown');
        } catch (TransportException) {
        }

        $this->assertCount(2, $downloader->requested);
        $this->assertStringContainsString('failed with HTTP 404 using the Packagist token', $io->getOutput());
    }

    public function testDoesNotRetryWhenAlreadyUsingPackagistToken(): void
    {
        $downloader = $this->createDownloader(new BufferIO(), true, static fn () => 'packagist-token', [self::createException(403)]);

        $this->expectException(TransportException::class);
        $downloader->get(self::REPO_URL);
    }

    #[TestWith([self::REPO_URL.'/contents/composer.json?ref=abc', 404])]
    #[TestWith([self::REPO_URL, 500])]
    #[TestWith(['https://gitlab.com/api/v4/projects/acme%2Flib', 403])]
    public function testIgnoresOtherFailures(string $url, int $status): void
    {
        $io = new BufferIO();
        $downloader = $this->createDownloader($io, false, static fn () => 'packagist-token', [self::createException($status)]);

        try {
            $downloader->get($url);
            $this->fail('Expected the TransportException to be rethrown');
        } catch (TransportException) {
        }

        $this->assertCount(1, $downloader->requested);
        if (500 === $status) {
            $this->assertStringContainsString('failed with HTTP 500', $io->getOutput());
        } else {
            $this->assertSame('', $io->getOutput());
        }
    }

    /**
     * @param list<string> $headers
     */
    private static function createException(int $status, array $headers = [], string $body = ''): TransportException
    {
        $e = new TransportException('HTTP/2 '.$status, $status);
        $e->setStatusCode($status);
        $e->setHeaders($headers);
        $e->setResponse($body);

        return $e;
    }

    /**
     * @param list<Response|TransportException> $responses
     */
    private function createDownloader(BufferIO $io, bool $usesPackagistToken, \Closure $tokenProvider, array $responses): FakeResponsesHttpDownloader
    {
        return new FakeResponsesHttpDownloader($io, new Config(false), $this->createStub(StatsDClient::class), $usesPackagistToken, 'acme', $tokenProvider, $responses);
    }
}

class FakeResponsesHttpDownloader extends LoggingHttpDownloader
{
    /** @var list<string> */
    public array $requested = [];

    /**
     * @param list<Response|TransportException> $responses
     */
    public function __construct(BufferIO $io, Config $config, StatsDClient $statsd, bool $usesPackagistToken, string $vendor, \Closure $tokenProvider, private array $responses)
    {
        parent::__construct($io, $config, $statsd, $usesPackagistToken, $vendor, $tokenProvider);
    }

    protected function doGet(string $url, array $options): Response
    {
        $this->requested[] = $url;
        $response = array_shift($this->responses) ?? throw new \LogicException('Unexpected request to '.$url);
        if ($response instanceof TransportException) {
            throw $response;
        }

        return $response;
    }
}
