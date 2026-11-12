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

namespace App\Tests\Package\Vcs;

use App\Entity\Package;
use App\Package\Git\BlockedHostException;
use App\Package\Git\LimitExceededException;
use App\Package\Git\PktLine;
use App\Package\Git\ProtocolException;
use App\Package\Git\RemoteRepository;
use App\Package\Git\RepositoryNotFoundException;
use App\Package\Git\RepositoryUnavailableException;
use App\Package\Git\ServerRefusedException;
use App\Package\Git\SmartHttpClient;
use App\Package\Git\UnsupportedRepositoryException;
use App\Package\Vcs\ForgejoDriver;
use App\Package\Vcs\GitBitbucketDriver;
use App\Package\Vcs\GitHubDriver;
use App\Package\Vcs\GitLabDriver;
use App\Package\Vcs\HttpGitDriver;
use App\Package\VcsRepositoryFactory;
use App\Service\VersionCache;
use App\Tests\Package\Git\GitHttpBackendDownloader;
use App\Tests\Package\Git\GitRepositoryBuilder;
use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\NullIO;
use Composer\Repository\Vcs\VcsDriver;
use Composer\Repository\VcsRepository;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class HttpGitDriverTest extends TestCase
{
    private const array SERVERS = [
        'v2 with filters' => [true, ['uploadpack.allowFilter' => 'true']],
        'v2 with tree filters capped at depth 0 like GitHub' => [true, ['uploadpack.allowFilter' => 'true', 'uploadpackfilter.tree.maxDepth' => '0']],
        'v2 without filters' => [true, []],
        'v0 with filters but tip wants only like Azure DevOps' => [false, ['uploadpack.allowFilter' => 'true']],
        'v0 with filters and reachable wants' => [false, ['uploadpack.allowFilter' => 'true', 'uploadpack.allowReachableSHA1InWant' => 'true']],
        'v0 without filters' => [false, []],
    ];

    private static string $projectRoot;
    /** @var array<string, array{tags: array<string, string>, branches: array<string, string>, times: array<string, int>}> */
    private static array $expected = [];
    /** @var array<string, string> commit ids by ref name */
    private static array $skippedRefs = [];

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = GitRepositoryBuilder::createTempDir('http-git-driver-test');

        foreach (['sha1', 'sha256'] as $format) {
            $builder = new GitRepositoryBuilder(self::$projectRoot.'/work-'.$format, $format);
            $big = str_repeat("a line that is long enough to make deltas worthwhile\n", 500);
            $first = $builder->commit(['composer.json' => '{"name":"acme/foo"}', 'README.md' => "# Foo\n", 'docs/README.md' => "docs\n", 'big.txt' => $big]);
            $builder->tag('v1.0.0');
            $second = $builder->commit(['composer.json' => '{"name":"acme/foo","description":"v1.1"}', 'big.txt' => $big."more\n"]);
            $builder->tag('v1.1.0', true);
            $builder->checkout('feature', true);
            $third = $builder->commit(['composer.json' => '{"name":"acme/foo","description":"feature"}']);
            $builder->checkout('main');
            $builder->publish(self::$projectRoot, 'foo-'.$format.'.git');

            self::$expected[$format] = [
                'tags' => ['v1.0.0' => $first, 'v1.1.0' => $second],
                'branches' => ['feature' => $third, 'main' => $second],
                'times' => [$first => $builder->authorTime($first), $second => $builder->authorTime($second), $third => $builder->authorTime($third)],
            ];
        }

        $builder = new GitRepositoryBuilder(self::$projectRoot.'/work-dangling');
        $builder->commit(['composer.json' => '{"name":"acme/dangling"}']);
        $builder->checkout('dev', true);
        $bare = $builder->publish(self::$projectRoot, 'dangling.git');
        GitRepositoryBuilder::run(['symbolic-ref', 'HEAD', 'refs/heads/gone'], $bare);

        GitRepositoryBuilder::run(['init', '-q', '--bare', self::$projectRoot.'/empty.git'], self::$projectRoot);

        $builder = new GitRepositoryBuilder(self::$projectRoot.'/work-odd-refs');
        $builder->commit(['composer.json' => '{"name":"acme/odd"}', 'notes.txt' => "notes\n"]);
        $builder->git('tag', '-a', '-m', 'blob', 'tag-of-blob', 'HEAD:notes.txt');
        $builder->git('tag', '-a', '-m', 'tree', 'tag-of-tree', 'HEAD^{tree}');
        $builder->publish(self::$projectRoot, 'odd-refs.git');

        $builder = new GitRepositoryBuilder(self::$projectRoot.'/work-skipped-refs');
        self::$skippedRefs['v1.0.0'] = $builder->commit(['composer.json' => '{"name":"acme/skipped"}']);
        $builder->tag('v1.0.0');
        self::$skippedRefs['nightly-build'] = $builder->commit(['composer.json' => '{"name":"acme/skipped","description":"nightly"}']);
        $builder->tag('nightly-build');
        self::$skippedRefs['main'] = $builder->commit(['composer.json' => '{"name":"acme/skipped","description":"main"}']);
        $builder->checkout('feature', true);
        self::$skippedRefs['feature'] = $builder->commit(['composer.json' => '{"name":"acme/skipped","description":"feature"}']);
        $builder->checkout('main');
        $builder->checkout('dependabot/composer/acme/bar-2.0', true);
        self::$skippedRefs['dependabot/composer/acme/bar-2.0'] = $builder->commit(['composer.json' => '{"name":"acme/skipped","description":"bump"}']);
        $builder->checkout('main');
        $builder->publish(self::$projectRoot, 'skipped-refs.git');
    }

    protected function tearDown(): void
    {
        // set by VcsRepositoryFactory
        Platform::clearEnv('GIT_ALLOW_PROTOCOL');
    }

    public static function tearDownAfterClass(): void
    {
        new Filesystem()->remove(self::$projectRoot);
    }

    /**
     * @param array<string, string> $serverConfig
     */
    #[DataProvider('provideServers')]
    public function testReadsRepository(string $format, bool $protocolV2, array $serverConfig, bool $batched, int $expectedRequests): void
    {
        $downloader = new GitHttpBackendDownloader(self::$projectRoot, $protocolV2, $serverConfig);
        $driver = $this->createDriver($downloader, 'foo-'.$format.'.git', $batched ? [] : null);
        $expected = self::$expected[$format];

        self::assertSame($expected['tags'], $driver->getTags());
        self::assertSame($expected['branches'], $driver->getBranches());
        self::assertSame('main', $driver->getRootIdentifier());

        $descriptions = ['v1.0.0' => null, 'v1.1.0' => 'v1.1', 'feature' => 'feature', 'main' => 'v1.1'];
        foreach ($expected['tags'] + $expected['branches'] as $ref => $commit) {
            $information = $driver->getComposerInformation($commit);
            self::assertNotNull($information);
            self::assertSame('acme/foo', $information['name']);
            self::assertSame($descriptions[$ref], $information['description'] ?? null);
            self::assertSame(gmdate(\DATE_RFC3339, $expected['times'][$commit]), $information['time']);
        }

        self::assertSame("docs\n", $driver->getFileContent('docs/README.md', 'main'));
        self::assertSame("# Foo\n", $driver->getFileContent('README.md', 'v1.0.0'));
        self::assertNull($driver->getFileContent('missing.md', 'main'));
        self::assertNull($driver->getFileContent('docs', 'main'), 'directories are not files');
        self::assertNull($driver->getFileContent('composer.json', 'no-such-branch'));
        self::assertEquals(new \DateTimeImmutable('@'.$expected['times'][$expected['branches']['feature']]), $driver->getChangeDate('feature'));

        self::assertCount($expectedRequests, $downloader->requests);
    }

    /**
     * @return iterable<string, array{string, bool, array<string, string>, bool, int}>
     */
    public static function provideServers(): iterable
    {
        // GET + ls-refs (v2 only), then fetches, which batching of the per-ref composer.json reads cuts down
        $requests = [
            'v2 with filters' => [11, 7],
            'v2 with tree filters capped at depth 0 like GitHub' => [15, 9],
            'v2 without filters' => [5, 3],
            'v0 with filters but tip wants only like Azure DevOps' => [4, 2],
            'v0 with filters and reachable wants' => [10, 6],
            'v0 without filters' => [4, 2],
        ];
        foreach (['sha1', 'sha256'] as $format) {
            foreach (self::SERVERS as $name => [$protocolV2, $serverConfig]) {
                yield $format.' '.$name => [$format, $protocolV2, $serverConfig, false, $requests[$name][0]];
                yield $format.' '.$name.' batched' => [$format, $protocolV2, $serverConfig, true, $requests[$name][1]];
            }
        }
    }

    public function testReadsFromServersOmittingLineFeeds(): void
    {
        foreach (self::SERVERS as $name => [$protocolV2, $serverConfig]) {
            $downloader = new GitHttpBackendDownloader(self::$projectRoot, $protocolV2, $serverConfig);
            $downloader->omitLineFeeds = true;
            $driver = $this->createDriver($downloader, 'foo-sha1.git', []);
            $expected = self::$expected['sha1'];

            self::assertSame($expected['tags'], $driver->getTags(), $name);
            self::assertSame($expected['branches'], $driver->getBranches(), $name);
            self::assertSame('main', $driver->getRootIdentifier(), $name);
            self::assertSame('feature', $driver->getComposerInformation($expected['branches']['feature'])['description'] ?? null, $name);
        }
    }

    public function testUsesTheCheapestFetchTheServerAllows(): void
    {
        $filters = static function (GitHttpBackendDownloader $downloader): array {
            $filters = [];
            foreach ($downloader->requests as $request) {
                if (preg_match('{filter ([^\n]+)\n}', (string) $request['body'], $match)) {
                    $filters[$match[1]] = true;
                }
            }

            return array_keys($filters);
        };

        foreach (['v2 with filters' => ['tree:1'], 'v2 with tree filters capped at depth 0 like GitHub' => ['tree:1', 'tree:0'], 'v2 without filters' => []] as $server => $expectedFilters) {
            $downloader = new GitHttpBackendDownloader(self::$projectRoot, ...self::SERVERS[$server]);
            $driver = $this->createDriver($downloader, 'foo-sha1.git');
            $driver->getComposerInformation('main');

            self::assertSame($expectedFilters, $filters($downloader), $server);
        }
    }

    public function testKnownReferencesAreNotFetched(): void
    {
        $expected = self::$expected['sha1'];
        $downloader = new GitHttpBackendDownloader(self::$projectRoot, ...self::SERVERS['v2 with filters']);
        $known = [$expected['tags']['v1.0.0'], $expected['branches']['feature']];
        $driver = $this->createDriver($downloader, 'foo-sha1.git', $known);

        $driver->getComposerInformation($expected['tags']['v1.1.0']);

        $fetch = $downloader->requests[2]['body'] ?? '';
        self::assertStringContainsString('command=fetch', $fetch);
        self::assertSame(1, substr_count($fetch, 'want '), 'only the unknown commit, which main and v1.1.0 share, is fetched');
        self::assertStringContainsString('want '.$expected['tags']['v1.1.0'], $fetch);
    }

    public function testFallsBackToSnapshotsWhenObjectsCannotBeFetchedById(): void
    {
        $downloader = new GitHttpBackendDownloader(self::$projectRoot, ...self::SERVERS['v2 with filters']);
        $downloader->interceptor = static function (string $url, string $method, ?string $body) {
            if ($body !== null && str_contains($body, 'command=fetch') && !str_contains($body, 'deepen')) {
                return GitHttpBackendDownloader::response(200, 'application/x-git-upload-pack-result', PktLine::encode("ERR upload-pack: not our ref\n"));
            }

            return null;
        };
        $driver = $this->createDriver($downloader, 'foo-sha1.git', []);

        self::assertSame('v1.1', $driver->getComposerInformation('main')['description'] ?? null);
        self::assertSame("docs\n", $driver->getFileContent('docs/README.md', 'main'));
        $lastFetch = (string) end($downloader->requests)['body'];
        self::assertStringContainsString('deepen 1', $lastFetch);
        self::assertStringNotContainsString('filter', $lastFetch);
    }

    public function testHeadPointingToAMissingBranchFallsBackToAnExistingOne(): void
    {
        $driver = $this->createDriver(new GitHttpBackendDownloader(self::$projectRoot), 'dangling.git');

        self::assertSame(['dev', 'main'], array_keys($driver->getBranches()));
        self::assertSame('dev', $driver->getRootIdentifier());
        self::assertSame('acme/dangling', $driver->getComposerInformation($driver->getRootIdentifier())['name'] ?? null);
    }

    #[DataProvider('provideServerNames')]
    public function testTagsOfNonCommitsHaveNoComposerJson(string $server): void
    {
        $driver = $this->createDriver(new GitHttpBackendDownloader(self::$projectRoot, ...self::SERVERS[$server]), 'odd-refs.git', []);

        self::assertSame(['tag-of-blob', 'tag-of-tree'], array_keys($driver->getTags()));
        foreach ($driver->getTags() as $oid) {
            self::assertNull($driver->getComposerInformation($oid));
            self::assertNull($driver->getChangeDate($oid));
        }
        self::assertSame('acme/odd', $driver->getComposerInformation('main')['name'] ?? null);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function provideServerNames(): iterable
    {
        foreach (array_keys(self::SERVERS) as $name) {
            yield $name => [$name];
        }
    }

    public function testEmptyRepository(): void
    {
        foreach ([true, false] as $protocolV2) {
            $driver = $this->createDriver(new GitHttpBackendDownloader(self::$projectRoot, $protocolV2), 'empty.git');

            self::assertSame([], $driver->getTags());
            self::assertSame([], $driver->getBranches());
            self::assertSame('master', $driver->getRootIdentifier());
            self::assertNull($driver->getComposerInformation('master'));
        }
    }

    public function testFollowsRedirectsLikeGit(): void
    {
        $downloader = new GitHttpBackendDownloader(self::$projectRoot);
        $downloader->redirects = [GitHttpBackendDownloader::BASE_URL.'old-name' => GitHttpBackendDownloader::BASE_URL.'foo-sha1.git'];
        $driver = $this->createDriver($downloader, 'old-name');

        self::assertSame('acme/foo', $driver->getComposerInformation('main')['name'] ?? null);
        self::assertSame(GitHttpBackendDownloader::BASE_URL.'old-name', $driver->getUrl(), 'the package keeps its URL');
        self::assertSame(GitHttpBackendDownloader::BASE_URL.'foo-sha1.git/git-upload-pack', $downloader->requests[1]['url']);
    }

    /**
     * @param \Closure(): \Composer\Util\Http\Response $response
     * @param class-string<\Throwable>                 $exception
     */
    #[DataProvider('provideFailingDiscoveries')]
    public function testDiscoveryFailures(\Closure $response, string $exception, string $message): void
    {
        $downloader = new GitHttpBackendDownloader(self::$projectRoot);
        $downloader->interceptor = static fn () => $response();

        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        $this->createDriver($downloader, 'foo-sha1.git');
    }

    /**
     * @return iterable<array{\Closure(): \Composer\Util\Http\Response, class-string<\Throwable>, string}>
     */
    public static function provideFailingDiscoveries(): iterable
    {
        $status = static fn (int $status) => static fn () => GitHttpBackendDownloader::response($status, 'text/plain', 'error');
        $throw = static fn (string $message) => static function () use ($message): never {
            throw new TransportException($message, 0);
        };

        yield '404' => [$status(404), RepositoryNotFoundException::class, 'HTTP 404'];
        yield '401 auth prompt' => [$status(401), RepositoryNotFoundException::class, 'HTTP 401'];
        yield '403' => [$status(403), RepositoryNotFoundException::class, 'HTTP 403'];
        yield 'login page' => [static fn () => GitHttpBackendDownloader::response(200, 'text/html; charset=utf-8', '<html>Sign in</html>'), RepositoryNotFoundException::class, 'got text/html instead of a git ref advertisement'];
        yield 'redirect away from the repository' => [static fn () => GitHttpBackendDownloader::response(200, 'application/x-git-upload-pack-advertisement', '', GitHttpBackendDownloader::BASE_URL.'users/sign_in'), RepositoryNotFoundException::class, 'redirected to an unexpected location'];
        yield 'dumb http' => [static fn () => GitHttpBackendDownloader::response(200, 'text/plain', str_repeat('a', 40)."\trefs/heads/main\n"), UnsupportedRepositoryException::class, 'dumb HTTP protocol'];
        yield '500' => [$status(500), RepositoryUnavailableException::class, 'HTTP 500'];
        yield '503' => [$status(503), RepositoryUnavailableException::class, 'HTTP 503'];
        yield '429' => [$status(429), RepositoryUnavailableException::class, 'HTTP 429'];
        yield 'dns failure' => [$throw('curl error 6 while downloading: Could not resolve host: git.example.org'), RepositoryUnavailableException::class, 'Could not resolve host'];
        yield 'private ip' => [$throw('IP "10.0.0.1" is blocked for "https://git.example.org/foo.git/info/refs?service=git-upload-pack".'), BlockedHostException::class, 'is blocked'];
        yield 'server error packet' => [static fn () => GitHttpBackendDownloader::response(200, 'application/x-git-upload-pack-advertisement', PktLine::encode("# service=git-upload-pack\n").PktLine::FLUSH.PktLine::encode("ERR access denied\n"), GitHttpBackendDownloader::BASE_URL.'foo-sha1.git/info/refs?service=git-upload-pack'), ServerRefusedException::class, 'access denied'];
    }

    public function testResponsesAboveTheSizeLimitAreRefused(): void
    {
        $client = new SmartHttpClient(new GitHttpBackendDownloader(self::$projectRoot), GitHttpBackendDownloader::BASE_URL.'foo-sha1.git', maxResponseSize: 10);

        $this->expectException(LimitExceededException::class);

        $client->listRefs();
    }

    public function testSnapshotBatchesAreSplitWhenTooLarge(): void
    {
        $commits = array_values(array_unique(self::$expected['sha1']['branches'] + self::$expected['sha1']['tags']));
        $url = GitHttpBackendDownloader::BASE_URL.'foo-sha1.git';

        $downloader = new GitHttpBackendDownloader(self::$projectRoot, ...self::SERVERS['v2 without filters']);
        new RemoteRepository(new SmartHttpClient($downloader, $url))->prefetch($commits, ['composer.json']);
        $combinedSize = (int) end($downloader->requests)['responseSize'];

        $downloader = new GitHttpBackendDownloader(self::$projectRoot, ...self::SERVERS['v2 without filters']);
        $repository = new RemoteRepository(new SmartHttpClient($downloader, $url, maxResponseSize: $combinedSize - 1));
        $repository->prefetch($commits, ['composer.json']);

        $fetches = array_filter($downloader->requests, static fn ($request) => str_contains((string) $request['body'], 'command=fetch'));
        self::assertGreaterThan(2, \count($fetches), 'the refused batch and its halves');
        foreach ($commits as $commit) {
            self::assertStringContainsString('acme/foo', (string) $repository->readFile($commit, 'composer.json'));
        }
    }

    public function testOnlyHttpsUrlsAreSupported(): void
    {
        $this->expectException(UnsupportedRepositoryException::class);

        $this->createDriver(new GitHttpBackendDownloader(self::$projectRoot), 'foo-sha1.git', null, 'http://8.8.8.8/');
    }

    #[DataProvider('provideUrls')]
    public function testSupportsAndHttpsConversion(string $url, bool $supported, ?string $httpsUrl): void
    {
        self::assertSame($supported, HttpGitDriver::supports(new NullIO(), new Config(false), $url));
        self::assertSame($httpsUrl, HttpGitDriver::toHttpsUrl($url));
    }

    /**
     * @return iterable<array{string, bool, ?string}>
     */
    public static function provideUrls(): iterable
    {
        yield 'https .git' => ['https://example.org/acme/foo.git', true, 'https://example.org/acme/foo.git'];
        yield 'https without .git' => ['https://example.org/acme/foo', true, 'https://example.org/acme/foo'];
        yield 'scp style from API driver fallbacks' => ['git@github.com:acme/foo.git', true, 'https://github.com/acme/foo.git'];
        yield 'ssh with port' => ['ssh://git@example.org:2222/acme/foo.git', true, 'https://example.org/acme/foo.git'];
        yield 'http' => ['http://example.org/acme/foo.git', false, null];
        yield 'git protocol' => ['git://example.org/acme/foo.git', false, null];
        yield 'file' => ['file:///tmp/foo.git', false, null];
        yield 'local path' => ['/tmp/foo.git', false, null];
    }

    public function testApiDriversFallBackToTheHttpDriver(): void
    {
        $config = $this->createConfig();
        $downloader = new GitHttpBackendDownloader(self::$projectRoot);
        $downloader->redirects = ['https://github.com/acme/foo' => GitHttpBackendDownloader::BASE_URL.'foo-sha1.git'];

        $github = new GitHubDriver(['url' => 'https://github.com/acme/foo', 'no-api' => true], new NullIO(), $config, $downloader, new ProcessExecutor());
        $github->initialize();
        self::assertSame(self::$expected['sha1']['tags'], $github->getTags());
        self::assertInstanceOf(HttpGitDriver::class, new \ReflectionProperty($github, 'gitDriver')->getValue($github));

        $fallbacks = [
            [GitLabDriver::class, 'https://gitlab.com/acme/foo', 'setupGitDriver', 'gitDriver'],
            [ForgejoDriver::class, 'https://codeberg.org/acme/foo', 'setupGitDriver', 'gitDriver'],
            [GitBitbucketDriver::class, 'https://bitbucket.org/acme/foo', 'setupFallbackDriver', 'fallbackDriver'],
        ];
        foreach ($fallbacks as [$class, $url, $method, $property]) {
            $driver = new $class(['url' => $url], new NullIO(), $config, $downloader, new ProcessExecutor());
            new \ReflectionMethod($driver, $method)->invoke($driver, GitHttpBackendDownloader::BASE_URL.'foo-sha1.git');
            $fallback = new \ReflectionProperty($driver, $property)->getValue($driver);
            self::assertInstanceOf(HttpGitDriver::class, $fallback, $class);
            self::assertSame(self::$expected['sha1']['branches'], $fallback->getBranches(), $class);
        }
    }

    public function testGitHubFallbackCanStillBeDisabled(): void
    {
        $driver = new GitHubDriver(['url' => 'https://github.com/acme/foo', 'no-api' => true, 'allow-git-fallback' => false], new NullIO(), $this->createConfig(), new GitHttpBackendDownloader(self::$projectRoot), new ProcessExecutor());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Fallback to git driver disabled');

        $driver->initialize();
    }

    public function testWorksThroughVcsRepository(): void
    {
        $repository = VcsRepositoryFactory::create(GitHttpBackendDownloader::BASE_URL.'foo-sha1.git', new NullIO(), $this->createConfig(), new GitHttpBackendDownloader(self::$projectRoot));

        $versions = array_map(static fn ($package) => $package->getPrettyVersion(), $repository->getPackages());
        sort($versions);

        self::assertInstanceOf(HttpGitDriver::class, $repository->getDriver());
        // 9999999-dev is the alias Composer adds for the default branch
        self::assertSame(['9999999-dev', 'dev-feature', 'dev-main', 'v1.0.0', 'v1.1.0'], $versions);
    }

    public function testMissingRepositoryWithoutGitSuffixFailsWithTheDiscoveryError(): void
    {
        $downloader = new GitHttpBackendDownloader(self::$projectRoot);
        $downloader->interceptor = static fn () => GitHttpBackendDownloader::response(404, 'text/plain', 'not found');
        $repository = VcsRepositoryFactory::create(GitHttpBackendDownloader::BASE_URL.'acme/missing', new NullIO(), $this->createConfig(), $downloader);

        try {
            $repository->getDriver();
            self::fail('Expected the missing repository to fail the driver initialization');
        } catch (RepositoryNotFoundException $e) {
            self::assertSame(404, $e->getStatusCode());
        }
        self::assertCount(1, $downloader->requests, 'no separate probe before the driver reads the refs');
    }

    public function testCommitsKnownToTheVersionCacheAreNotFetched(): void
    {
        $downloader = new GitHttpBackendDownloader(self::$projectRoot);
        $versions = [];
        foreach (['v1.0.0' => '1.0.0.0', 'v1.1.0' => '1.1.0.0'] as $tag => $normalized) {
            $versions[] = ['version' => $tag, 'normalizedVersion' => $normalized, 'source' => ['type' => 'git', 'url' => '', 'reference' => self::$expected['sha1']['tags'][$tag]], 'defaultBranch' => 0];
        }
        foreach (self::$expected['sha1']['branches'] as $branch => $commit) {
            $versions[] = ['version' => 'dev-'.$branch, 'normalizedVersion' => 'dev-'.$branch, 'source' => ['type' => 'git', 'url' => '', 'reference' => $commit], 'defaultBranch' => (int) ($branch === 'main')];
        }

        $versionCache = new VersionCache(new Package(), $versions, []);
        $repository = VcsRepositoryFactory::create(GitHttpBackendDownloader::BASE_URL.'foo-sha1.git', new NullIO(), $this->createConfig(), $downloader, $versionCache, [], $versionCache->getKnownReferences());

        self::assertCount(5, $repository->getPackages());
        // ref listing, then VcsRepository reads the package name from the default branch
        self::assertSame(['GET', 'POST', 'POST'], array_column($downloader->requests, 'method'));
        preg_match_all('{want ([0-9a-f]+)}', (string) $downloader->requests[2]['body'], $wants);
        self::assertSame([self::$expected['sha1']['branches']['main']], $wants[1]);
    }

    public function testRefsVcsRepositorySkipsAreNotPrefetched(): void
    {
        $downloader = new GitHttpBackendDownloader(self::$projectRoot);
        $repository = VcsRepositoryFactory::create(GitHttpBackendDownloader::BASE_URL.'skipped-refs.git', new NullIO(), $this->createConfig(), $downloader, null, [], []);

        $versions = array_map(static fn ($package) => $package->getPrettyVersion(), $repository->getPackages());
        sort($versions);
        self::assertSame(['9999999-dev', 'dev-feature', 'dev-main', 'v1.0.0'], $versions);

        preg_match_all('{want ([0-9a-f]{40})}', implode("\n", array_map(static fn ($request) => (string) $request['body'], $downloader->requests)), $wants);
        foreach (['main', 'feature', 'v1.0.0'] as $ref) {
            self::assertContains(self::$skippedRefs[$ref], $wants[1], $ref.' is fetched');
        }
        // excluded by VcsRepositoryFactory::EXCLUDED_BRANCHES, and not a valid version
        foreach (['dependabot/composer/acme/bar-2.0', 'nightly-build'] as $ref) {
            self::assertNotContains(self::$skippedRefs[$ref], $wants[1], $ref.' is not fetched');
        }
    }

    /**
     * @param \Closure(): \Composer\Util\Http\Response $response
     * @param class-string<\Throwable>                 $exception
     */
    #[DataProvider('provideVersionReadFailures')]
    public function testFailuresReadingAVersionAbortTheUpdate(\Closure $response, string $exception): void
    {
        $downloader = new GitHttpBackendDownloader(self::$projectRoot);
        $commit = self::$expected['sha1']['tags']['v1.0.0'];
        $downloader->interceptor = static fn (string $url, string $method, ?string $body) => $body !== null && str_contains($body, 'want '.$commit) ? $response() : null;
        $repository = new VcsRepository(['url' => GitHttpBackendDownloader::BASE_URL.'foo-sha1.git'], new NullIO(), $this->createConfig(), $downloader, null, null, ['git' => HttpGitDriver::class]);

        // VcsRepository would otherwise skip v1.0.0 and carry on, and Packagist then deletes the version as gone upstream
        $this->expectException($exception);

        $repository->getPackages();
    }

    /**
     * @return iterable<string, array{\Closure(): \Composer\Util\Http\Response, class-string<\Throwable>}>
     */
    public static function provideVersionReadFailures(): iterable
    {
        yield '503' => [static fn () => GitHttpBackendDownloader::response(503, 'text/plain', 'error'), RepositoryUnavailableException::class];
        yield 'timeout' => [static function (): never {
            throw new TransportException('curl error 28 while downloading: Operation timed out', 0);
        }, RepositoryUnavailableException::class];
        yield 'host now resolving to a private ip' => [static function (): never {
            throw new TransportException('IP "10.0.0.1" is blocked for "https://git.example.org/foo.git/git-upload-pack".', 0);
        }, BlockedHostException::class];
        yield 'garbage instead of a pack' => [static fn () => GitHttpBackendDownloader::response(200, 'application/x-git-upload-pack-result', 'not a pkt-line'), ProtocolException::class];
        yield 'refused' => [static fn () => GitHttpBackendDownloader::response(200, 'application/x-git-upload-pack-result', PktLine::encode("ERR upload-pack: not our ref\n")), ServerRefusedException::class];
    }

    public function testPacksAreInflatedWithinTheStorageBudget(): void
    {
        [$commit, $client] = self::largeRepository();
        $repository = new RemoteRepository($client, maxStoredBytes: 20 * 1024 * 1024);

        $this->expectException(LimitExceededException::class);
        // refused while inflating, not once the whole pack sits in memory
        $this->expectExceptionMessage('Pack contents exceed the limit of 20971520 bytes');

        $repository->readFile($commit, 'composer.json');
    }

    public function testPacksAreInflatedWithinTheMemoryLimit(): void
    {
        [$commit, $client] = self::largeRepository();
        $repository = new RemoteRepository($client);
        $memoryLimit = (string) \ini_get('memory_limit');
        // leaves 120 MB, of which a pack may use half, so the 90 MB of blobs are refused after a few of them
        ini_set('memory_limit', (intdiv(memory_get_usage(true), 1024 * 1024) + 120).'M');

        $this->expectException(LimitExceededException::class);
        $this->expectExceptionMessage('Pack contents exceed the limit of');

        try {
            $repository->readFile($commit, 'composer.json');
        } finally {
            ini_set('memory_limit', $memoryLimit);
        }
    }

    /**
     * @return array{string, SmartHttpClient} the commit of a repository whose blobs inflate to 90 MB from a pack of a few hundred KB, and a client for it
     */
    private static function largeRepository(): array
    {
        static $commit = null;
        if ($commit === null) {
            $builder = new GitRepositoryBuilder(self::$projectRoot.'/work-large');
            $files = ['composer.json' => '{"name":"acme/large"}'];
            for ($i = 0; $i < 6; $i++) {
                $files['blob-'.$i.'.txt'] = str_repeat(\sprintf("%-59s\n", 'blob '.$i), 262_144);
            }
            $commit = $builder->commit($files);
            $builder->publish(self::$projectRoot, 'large.git');
        }

        // deltas between the blobs take git seconds to find and the parser seconds to apply, which is not what these tests are about
        $downloader = new GitHttpBackendDownloader(self::$projectRoot, true, ['pack.window' => '0']);

        return [$commit, new SmartHttpClient($downloader, GitHttpBackendDownloader::BASE_URL.'large.git')];
    }

    /**
     * @param ?list<string> $knownReferences
     */
    private function createDriver(GitHttpBackendDownloader $downloader, string $path, ?array $knownReferences = null, string $baseUrl = GitHttpBackendDownloader::BASE_URL): VcsDriver
    {
        $repoConfig = ['url' => $baseUrl.$path];
        if ($knownReferences !== null) {
            $repoConfig['known-references'] = $knownReferences;
        }
        $driver = new HttpGitDriver($repoConfig, new NullIO(), $this->createConfig(), $downloader, new ProcessExecutor());
        $driver->initialize();

        return $driver;
    }

    private function createConfig(): Config
    {
        $config = new Config(false);
        $config->merge(['config' => ['cache-dir' => self::$projectRoot.'/cache', 'home' => self::$projectRoot.'/home']]);

        return $config;
    }
}
