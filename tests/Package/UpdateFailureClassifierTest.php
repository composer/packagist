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

use App\Package\Git\ProtocolException;
use App\Package\Git\RepositoryNotFoundException;
use App\Package\Git\RepositoryUnavailableException;
use App\Package\Git\UnsupportedRepositoryException;
use App\Package\PrivateRepositoryHostException;
use App\Package\UpdateFailureClassifier;
use App\Package\UpdateFailureKind;
use App\Package\UpdateFailureVerdict;
use Composer\Downloader\TransportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UpdateFailureClassifierTest extends TestCase
{
    #[DataProvider('provideFailures')]
    public function testClassify(\Throwable $e, ?UpdateFailureKind $expectedKind, ?string $expectedCode): void
    {
        $verdict = UpdateFailureClassifier::classify($e);

        self::assertSame($expectedKind, $verdict?->kind);
        self::assertSame($expectedCode, $verdict?->code);
    }

    /**
     * @return iterable<string, array{\Throwable, ?UpdateFailureKind, ?string}>
     */
    public static function provideFailures(): iterable
    {
        $gone = UpdateFailureKind::Gone;
        $unreachable = UpdateFailureKind::Unreachable;

        yield 'git remote repository not found' => [
            self::gitFailure("Cloning into bare repository '/tmp/x.git'...\nremote: Repository not found.\nfatal: repository 'https://github.com/acme/lib.git/' not found\n"),
            $gone, 'git_remote_repository_not_found',
        ];
        yield 'gitlab ssh access denied' => [
            self::gitFailure("git@gitlab.com: Permission denied (publickey).\nfatal: Could not read from remote repository.\n\nPlease make sure you have the correct access rights\nand the repository exists."),
            $gone, 'gitlab_git_no_access',
        ];
        yield 'gitlab ssh transport not allowed' => [
            self::gitFailure("Cloning into bare repository '/tmp/x.git'...\nfatal: transport 'ssh' not allowed", 'git@gitlab.com:acme/lib.git'),
            $gone, 'gitlab_git_no_access',
        ];
        yield 'gitlab download forbidden' => [
            self::gitFailure("remote: You are not allowed to download code from this project.\nfatal: unable to access 'https://oauth2:***@gitlab.com/acme/lib.git/': The requested URL returned error: 403", 'https://oauth2:***@gitlab.com/acme/lib.git'),
            $gone, 'gitlab_git_download_forbidden',
        ];
        yield 'gitlab api 404' => [
            self::transport('The "https://gitlab.com/api/v4/projects/acme%2Flib" file could not be downloaded (HTTP/2 404 ): {"message":"404 Project Not Found"}', 404),
            $gone, 'gitlab_api_404',
        ];
        yield 'bitbucket ssh access denied' => [
            self::gitFailure("git@bitbucket.org: Permission denied (publickey).\nfatal: Could not read from remote repository.\n\nPlease make sure you have the correct access rights\nand the repository exists.", 'git@bitbucket.org:acme/lib.git'),
            $gone, 'bitbucket_git_no_access',
        ];
        yield 'bitbucket ssh transport not allowed' => [
            self::gitFailure("Cloning into bare repository '/tmp/x.git'...\nfatal: transport 'ssh' not allowed", 'git@bitbucket.org:acme/lib.git'),
            $gone, 'bitbucket_git_no_access',
        ];
        yield 'bitbucket api 404' => [
            self::transport('The "https://api.bitbucket.org/2.0/repositories/acme/lib?fields=-project" file could not be downloaded (HTTP/2 404 )', 404),
            $gone, 'bitbucket_api_404',
        ];
        yield 'github account disabled' => [
            self::gitFailure("remote: Repository 'acme/lib' is disabled.\nremote: Please ask the owner to check their account.\nfatal: unable to access 'https://x:***@github.com/acme/lib.git/': The requested URL returned error: 403", 'https://x:***@github.com/acme/lib.git'),
            $gone, 'github_git_account_disabled',
        ];
        yield 'github account suspended' => [
            self::gitFailure("remote: Your account is suspended. Please visit https://support.github.com for more information.\nfatal: unable to access 'https://x:***@github.com/acme/lib.git/': The requested URL returned error: 403", 'https://x:***@github.com/acme/lib.git'),
            $gone, 'github_git_account_suspended',
        ];
        yield 'github repository disabled' => [
            self::gitFailure("remote: Access to this repository has been disabled by GitHub staff due to excessive resource use.\nfatal: unable to access 'https://x:***@github.com/acme/lib.git/': The requested URL returned error: 403", 'https://x:***@github.com/acme/lib.git'),
            $gone, 'github_git_repository_disabled',
        ];
        yield 'github git 403' => [
            self::gitFailure("remote: Write access to repository not granted.\nfatal: unable to access 'https://x:***@github.com/acme/lib.git/': The requested URL returned error: 403", 'https://x:***@github.com/acme/lib.git'),
            $gone, 'github_git_403',
        ];
        yield 'git repository not found' => [
            self::gitFailure("fatal: repository 'https://git.example.org/acme/lib.git/' not found\n"),
            $gone, 'git_repository_not_found',
        ];
        yield 'git authentication failed' => [
            self::gitFailure("fatal: Authentication failed for 'https://git.example.org/acme/lib.git/'"),
            $gone, 'git_authentication_failed',
        ];
        yield 'no driver' => [
            new \RuntimeException('Driver could not be established for package encoredigitalgroup/laravel-cache-prune (https://gitlab.encoredigitalgroup.com/oss/laravel-cache-prune)'),
            $unreachable, 'no_driver',
        ];
        yield 'github api empty repository' => [
            self::transport('The "https://api.github.com/repos/acme/lib/git/refs/heads?per_page=100" file could not be downloaded (HTTP/2 409 )', 409),
            $gone, 'github_api_empty_repository',
        ];
        yield 'github api 404' => [
            self::transport('The "https://api.github.com/repos/acme/lib" file could not be downloaded (HTTP/2 404 )', 404),
            $gone, 'github_api_404',
        ];
        yield 'github api 451' => [
            self::transport('The "https://api.github.com/repos/acme/lib" file could not be downloaded (HTTP/2 451 )', 451),
            $gone, 'github_api_451',
        ];
        yield 'github api access blocked' => [
            self::transport('The "https://api.github.com/repos/acme/lib" file could not be downloaded (HTTP/2 403 )', 403, '{"message":"Repository access blocked","block":{"reason":"tos"}}'),
            $gone, 'github_api_access_blocked',
        ];

        yield 'github api org ip allow list' => [
            self::transport('The "https://api.github.com/repos/acme/lib" file could not be downloaded (HTTP/2 403 )', 403, '{"message":"Although you appear to have the correct authorization credentials, the `acme` organization has an IP allow list enabled, and your IP address is not permitted to access this resource.","documentation_url":"https://docs.github.com/rest","status":"403"}'),
            $unreachable, 'github_api_ip_allow_list',
        ];
        yield 'private host' => [
            new PrivateRepositoryHostException('The repository host git.example.org resolves to a private or reserved IP address, only publicly reachable repositories are supported'),
            $unreachable, 'private_host',
        ];
        yield 'private host message on a plain exception' => [
            new \RuntimeException('The repository host git.example.org resolves to a private or reserved IP address, only publicly reachable repositories are supported'),
            null, null,
        ];

        yield 'unresolvable host' => [
            self::gitFailure("fatal: unable to access 'https://git.example.org/acme/lib.git/': Could not resolve host: git.example.org"),
            $unreachable, 'host_unreachable',
        ];
        yield 'connection refused' => [
            self::gitFailure("fatal: unable to access 'https://git.example.org/acme/lib.git/': Failed to connect to git.example.org port 443 after 12 ms: Connection refused"),
            $unreachable, 'host_unreachable',
        ];
        yield 'connection refused from the http client' => [
            self::transport('curl error 7 while downloading https://git.example.org/api/v4/projects/acme%2Flib: Failed to connect to git.example.org port 443 after 3 ms: Connection refused', 0),
            $unreachable, 'host_unreachable',
        ];

        yield 'github api 403 not blocked' => [
            self::transport('The "https://api.github.com/repos/acme/lib" file could not be downloaded (HTTP/2 403 )', 403, '{"message":"Resource not accessible"}'),
            null, null,
        ];
        yield 'github api 404 on another endpoint' => [
            self::transport('The "https://api.github.com/repos/acme/lib/contents/composer.json?ref=abc" file could not be downloaded (HTTP/2 404 )', 404),
            null, null,
        ];
        yield 'generic transport failure' => [
            self::transport('The "https://api.github.com/repos/acme/lib" file could not be downloaded (HTTP/2 502 )', 502),
            null, null,
        ];
        yield 'github sso authorization required' => [
            // thrown by Composer's AuthHelper when the token lacks SAML SSO authorization for the org
            new TransportException('Could not authenticate against github.com', 403),
            null, null,
        ];
        yield 'github api sso authorization required' => [
            self::transport('The "https://api.github.com/repos/acme/lib" file could not be downloaded (HTTP/2 403 )', 403, '{"message":"Resource protected by organization SAML enforcement. You must grant your Personal Access token access to this organization."}'),
            null, null,
        ];
        yield 'git http 404' => [
            new RepositoryNotFoundException('Failed to read https://git.example.org/acme/lib.git: The "https://git.example.org/acme/lib.git/info/refs?service=git-upload-pack" file could not be downloaded (HTTP/2 404 )', 404),
            $gone, 'git_http_not_found',
        ];
        yield 'git http 410' => [
            new RepositoryNotFoundException('Failed to read https://git.example.org/acme/lib.git', 410),
            $gone, 'git_http_not_found',
        ];
        yield 'git http auth prompt' => [
            new RepositoryNotFoundException('Failed to read https://git.example.org/acme/lib.git', 401),
            $unreachable, 'git_http_not_public',
        ];
        yield 'git http forbidden' => [
            new RepositoryNotFoundException('Failed to read https://git.example.org/acme/lib.git', 403),
            $unreachable, 'git_http_not_public',
        ];
        yield 'git http login page' => [
            new RepositoryNotFoundException('No git repository found at https://git.example.org/acme/lib.git (got text/html instead of a git ref advertisement)'),
            $unreachable, 'git_http_not_public',
        ];
        yield 'git http server error' => [
            new RepositoryUnavailableException('Failed to read https://git.example.org/acme/lib.git', 502),
            $unreachable, 'git_http_unavailable',
        ];
        // the curl message alone would match the git binary's host_unreachable
        yield 'git http connection failure' => [
            new RepositoryUnavailableException('Failed to read https://git.example.org/acme/lib.git: Failed to connect to git.example.org port 443 after 57 ms: Couldn\'t connect to server'),
            $unreachable, 'git_http_unavailable',
        ];
        yield 'git http dumb server' => [
            new UnsupportedRepositoryException('The server at https://git.example.org/acme/lib.git only supports the dumb HTTP protocol, which is not supported. Enable smart HTTP on your git server.'),
            null, null,
        ];
        // a 404 status on something else than the repository lookup does not make it gone
        yield 'git http protocol error' => [
            new ProtocolException('The server response contained no pack', 404),
            null, null,
        ];
        yield 'unrelated runtime exception' => [
            new \RuntimeException('Something unexpected happened'),
            null, null,
        ];
        yield 'not a runtime exception' => [
            new \LogicException('remote: Repository not found'),
            null, null,
        ];
    }

    public function testOnlyFailuresThatMayClearUpEscalateToGone(): void
    {
        $allowList = self::transport('The "https://api.github.com/repos/acme/lib" file could not be downloaded (HTTP/2 403 )', 403, '{"message":"the `acme` organization has an IP allow list enabled"}');

        self::assertTrue(UpdateFailureClassifier::classify($allowList)?->escalatesToGone);
        self::assertTrue(UpdateFailureClassifier::classify(new PrivateRepositoryHostException('The repository host git.example.org resolves to a private or reserved IP address'))?->escalatesToGone);
        self::assertTrue(UpdateFailureClassifier::classify(new \RuntimeException('Driver could not be established for package acme/lib (https://git.example.org/acme/lib)'))?->escalatesToGone);
        self::assertFalse(UpdateFailureClassifier::classify(self::gitFailure("fatal: unable to access 'https://git.example.org/acme/lib.git/': Could not resolve host: git.example.org"))?->escalatesToGone);
        self::assertTrue(UpdateFailureClassifier::classify(new RepositoryNotFoundException('Failed to read https://git.example.org/acme/lib.git', 401))?->escalatesToGone);
        self::assertFalse(UpdateFailureClassifier::classify(new RepositoryUnavailableException('Failed to read https://git.example.org/acme/lib.git', 503))?->escalatesToGone);
    }

    public function testDescribeSanitizesCredentialsAndTruncates(): void
    {
        $e = self::gitFailure("fatal: repository 'https://x-token-auth:supersecrettokenvalue@git.example.org/acme/lib.git/' not found\n".str_repeat('x', 2000));
        $description = UpdateFailureClassifier::describe(new UpdateFailureVerdict(UpdateFailureKind::Gone, 'git_repository_not_found'), $e);

        self::assertStringStartsWith('git_repository_not_found: RuntimeException: Failed to execute git clone', $description);
        self::assertStringNotContainsString('supersecrettokenvalue', $description);
        self::assertStringContainsString('x-token-auth:***@git.example.org', $description);
        self::assertSame(1000, mb_strwidth($description));
        self::assertStringEndsWith('…', $description);
    }

    private static function gitFailure(string $output, string $url = 'https://git.example.org/acme/lib.git'): \RuntimeException
    {
        return new \RuntimeException("Failed to execute git clone --mirror -- '".$url."' '/tmp/x.git'\n\n".$output);
    }

    private static function transport(string $message, int $status, ?string $response = null): TransportException
    {
        $e = new TransportException($message, $status);
        $e->setStatusCode($status);
        $e->setResponse($response);

        return $e;
    }
}
