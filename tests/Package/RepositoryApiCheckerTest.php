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

namespace App\Tests\Package;

use App\Package\RepositoryApiChecker;
use App\Package\RepositoryApiState;
use Composer\Downloader\TransportException;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RepositoryApiCheckerTest extends TestCase
{
    private const string GITHUB_REFERENCE = 'https://api.github.com/repos/composer/composer/git/refs/heads';
    private const string GITLAB_REFERENCE = 'https://gitlab.com/api/v4/projects/packagist%2Fvcs-repository-test';
    private const string BITBUCKET_REFERENCE = 'https://api.bitbucket.org/2.0/repositories/atlassian/atlaskit-mk-2?fields=-project';

    /**
     * @param array<string, int|TransportException> $responses status code or exception per requested url, unlisted urls fail the test
     */
    #[DataProvider('provideChecks')]
    public function testCheck(string $repositoryUrl, array $responses, ?RepositoryApiState $expected): void
    {
        $httpDownloader = $this->createStub(HttpDownloader::class);
        $httpDownloader->method('get')->willReturnCallback(static function (string $url) use ($responses): Response {
            self::assertArrayHasKey($url, $responses, 'Unexpected request to '.$url);
            if ($responses[$url] instanceof TransportException) {
                throw $responses[$url];
            }

            return new Response(['url' => $url], $responses[$url], [], '{}');
        });

        self::assertSame($expected, new RepositoryApiChecker()->check($repositoryUrl, $httpDownloader));
    }

    /**
     * @return iterable<string, array{string, array<string, int|TransportException>, ?RepositoryApiState}>
     */
    public static function provideChecks(): iterable
    {
        $github = 'https://api.github.com/repos/acme/lib/git/refs/heads';
        yield 'github exists' => ['https://github.com/acme/lib', [$github => 200], RepositoryApiState::Exists];
        yield 'github ssh url with .git' => ['git@github.com:acme/lib.git', [$github => 200], RepositoryApiState::Exists];
        yield 'github 404' => ['https://github.com/acme/lib', [$github => self::error(404), self::GITHUB_REFERENCE => 200], RepositoryApiState::Missing];
        yield 'github empty repository' => ['https://github.com/acme/lib', [$github => self::error(409), self::GITHUB_REFERENCE => 200], RepositoryApiState::Missing];
        yield 'github dmca takedown' => ['https://github.com/acme/lib', [$github => self::error(451), self::GITHUB_REFERENCE => 200], RepositoryApiState::Missing];
        yield 'github access blocked' => ['https://github.com/acme/lib', [$github => self::error(403, '{"message":"Repository access blocked","block":{"reason":"tos","created_at":"2026-01-01T00:00:00Z"}}'), self::GITHUB_REFERENCE => 200], RepositoryApiState::Missing];
        yield 'github access blocked, pretty printed' => ['https://github.com/acme/lib', [$github => self::error(403, "{\n  \"message\": \"Repository access blocked\"\n}"), self::GITHUB_REFERENCE => 200], RepositoryApiState::Missing];
        yield 'github 404 while the reference fails' => ['https://github.com/acme/lib', [$github => self::error(404), self::GITHUB_REFERENCE => self::error(503)], RepositoryApiState::Unknown];
        yield 'github 403' => ['https://github.com/acme/lib', [$github => self::error(403, '{"message":"Although you appear to have the correct authorization credentials, the `acme` organization has an IP allow list enabled"}')], RepositoryApiState::Unknown];
        yield 'github 500' => ['https://github.com/acme/lib', [$github => self::error(500)], RepositoryApiState::Unknown];

        $gitlab = 'https://gitlab.com/api/v4/projects/acme%2Flib';
        yield 'gitlab exists' => ['https://gitlab.com/acme/lib', [$gitlab => 200], RepositoryApiState::Exists];
        yield 'gitlab url with .git' => ['https://gitlab.com/acme/lib.git', [$gitlab => 200], RepositoryApiState::Exists];
        yield 'gitlab url with trailing slash' => ['https://gitlab.com/acme/lib/', [$gitlab => 200], RepositoryApiState::Exists];
        yield 'gitlab tree page url' => ['https://gitlab.com/acme/lib/-/tree/main', [$gitlab => 200], RepositoryApiState::Exists];
        yield 'gitlab ssh url' => ['git@gitlab.com:acme/lib.git', [$gitlab => 200], RepositoryApiState::Exists];
        yield 'gitlab subgroup' => ['https://gitlab.com/acme/tools/lib.git', ['https://gitlab.com/api/v4/projects/acme%2Ftools%2Flib' => 200], RepositoryApiState::Exists];
        yield 'gitlab 404' => ['https://gitlab.com/acme/lib', [$gitlab => self::error(404), self::GITLAB_REFERENCE => 200], RepositoryApiState::Missing];
        yield 'gitlab 403' => ['https://gitlab.com/acme/lib', [$gitlab => self::error(403)], RepositoryApiState::Unknown];

        $bitbucket = 'https://api.bitbucket.org/2.0/repositories/acme/lib?fields=-project';
        yield 'bitbucket exists' => ['https://bitbucket.org/acme/lib.git', [$bitbucket => 200], RepositoryApiState::Exists];
        yield 'bitbucket ssh url' => ['git@bitbucket.org:acme/lib.git', [$bitbucket => 200], RepositoryApiState::Exists];
        yield 'bitbucket ssh subdomain url' => ['git@ssh.bitbucket.org:acme/lib.git', [$bitbucket => 200], RepositoryApiState::Exists];
        yield 'bitbucket ssh subdomain url with scheme' => ['ssh://git@ssh.bitbucket.org/acme/lib.git', [$bitbucket => 200], RepositoryApiState::Exists];
        yield 'bitbucket 404' => ['https://bitbucket.org/acme/lib', [$bitbucket => self::error(404), self::BITBUCKET_REFERENCE => 200], RepositoryApiState::Missing];
        yield 'bitbucket 404 while the reference fails' => ['https://bitbucket.org/acme/lib', [$bitbucket => self::error(404), self::BITBUCKET_REFERENCE => self::error(500)], RepositoryApiState::Unknown];
        yield 'bitbucket 403' => ['https://bitbucket.org/acme/lib', [$bitbucket => self::error(403)], RepositoryApiState::Unknown];

        yield 'unsupported host' => ['https://git.example.org/acme/lib.git', [], null];
    }

    private static function error(int $status, ?string $response = null): TransportException
    {
        $e = new TransportException('HTTP '.$status, $status);
        $e->setStatusCode($status);
        $e->setResponse($response);

        return $e;
    }
}
