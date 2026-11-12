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

use App\Package\PrivateRepositoryHostException;
use App\Package\Vcs\HttpGitDriver;
use App\Package\VcsRepositoryFactory;
use App\Tests\Package\Git\GitHttpBackendDownloader;
use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\NullIO;
use Composer\Package\PackageInterface;
use Composer\Repository\Vcs\GitDriver;
use Composer\Repository\Vcs\GitHubDriver;
use Composer\Repository\VcsRepository;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VcsRepositoryFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Platform::clearEnv('GIT_ALLOW_PROTOCOL');
    }

    public function testGitHubIsOnlyReachedOverHttps(): void
    {
        self::assertSame(['https'], VcsRepositoryFactory::createConfig()->get('github-protocols'));
    }

    public function testGitIsLimitedToHttps(): void
    {
        $this->createRepository();

        self::assertSame('https', Platform::getEnv('GIT_ALLOW_PROTOCOL'));
    }

    #[DataProvider('provideDisallowedUrls')]
    public function testGitRefusesOtherTransportsBeforeConnecting(string $url): void
    {
        $this->createRepository();

        $process = new ProcessExecutor();
        self::assertNotSame(0, $process->execute(['git', 'ls-remote', '--', $url], $output, sys_get_temp_dir()));
        self::assertStringContainsString("transport '".strstr($url, ':', true)."' not allowed", $process->getErrorOutput());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function provideDisallowedUrls(): iterable
    {
        yield 'http' => ['http://packagist.invalid/acme/foo.git'];
        yield 'git' => ['git://packagist.invalid/acme/foo.git'];
        yield 'ssh' => ['ssh://git@packagist.invalid/acme/foo.git'];
        yield 'ftp' => ['ftp://packagist.invalid/acme/foo.git'];
        yield 'file' => ['file:///tmp/acme-foo.git'];
    }

    #[DataProvider('providePrivateHostUrls')]
    public function testPrivateHostsAreRefused(string $url): void
    {
        $this->expectException(PrivateRepositoryHostException::class);
        $this->expectExceptionMessage('resolves to a private or reserved IP address');

        $this->createRepository($url);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function providePrivateHostUrls(): iterable
    {
        yield 'loopback' => ['https://127.0.0.1/acme/foo.git'];
        yield 'localhost via /etc/hosts' => ['https://localhost/acme/foo.git'];
        yield 'decimal loopback' => ['https://2130706433/acme/foo.git'];
        yield 'rfc1918 with port' => ['https://10.1.2.3:8443/acme/foo.git'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data'];
        yield 'ipv6 loopback' => ['https://[::1]/acme/foo.git'];
        yield 'ipv6 unique local' => ['https://[fd00::1]/acme/foo.git'];
        yield 'ipv4-mapped ipv6' => ['https://[::ffff:127.0.0.1]/acme/foo.git'];
    }

    public function testPublicHostsAreAccepted(): void
    {
        self::assertSame('https://8.8.8.8/acme/foo.git', $this->createRepository('https://8.8.8.8/acme/foo.git')->getRepoConfig()['url']);
    }

    public function testGitRepositoriesAreNotReadWithTheGitBinary(): void
    {
        $composerDrivers = [
            'github' => GitHubDriver::class,
            'gitlab' => \Composer\Repository\Vcs\GitLabDriver::class,
            'git-bitbucket' => \Composer\Repository\Vcs\GitBitbucketDriver::class,
            'forgejo' => \Composer\Repository\Vcs\ForgejoDriver::class,
            'git' => GitDriver::class,
        ];

        self::assertSame(array_keys($composerDrivers), array_keys(VcsRepositoryFactory::DRIVERS));
        foreach (VcsRepositoryFactory::DRIVERS as $type => $class) {
            self::assertNotSame($composerDrivers[$type], $class);
            // the Updater checks drivers against Composer's classes
            self::assertTrue(is_a($class, $composerDrivers[$type], true), $class.' must stay a '.$composerDrivers[$type]);
        }
        self::assertSame(HttpGitDriver::class, VcsRepositoryFactory::DRIVERS['git']);
    }

    public function testKnownReferencesAreGivenToTheDriver(): void
    {
        $io = new NullIO();
        $config = VcsRepositoryFactory::createConfig();

        $repository = VcsRepositoryFactory::create('https://8.8.8.8/acme/foo.git', $io, $config, new HttpDownloader($io, $config), knownReferences: ['aaaa']);

        self::assertSame(['aaaa'], $repository->getRepoConfig()['known-references']);
        self::assertArrayNotHasKey('known-references', $this->createRepository()->getRepoConfig(), 'reads stay lazy unless asked for');
    }

    public function testUrlsWithoutHostAreRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not determine the host');

        $this->createRepository('https:///acme/foo.git');
    }

    public function testGitHubGitFallbackIsDisabled(): void
    {
        self::assertFalse($this->createRepository()->getRepoConfig()['allow-git-fallback']);
    }

    public function testShortLivedAutomatedBranchesAreNotRead(): void
    {
        self::assertSame(VcsRepositoryFactory::EXCLUDED_BRANCHES, $this->createRepository()->getRepoConfig()['exclude-branches']);

        $io = new NullIO();
        $config = VcsRepositoryFactory::createConfig();
        $repository = new VcsRepository(
            ['url' => 'https://8.8.8.8/acme/foo.git', 'type' => 'git', 'exclude-branches' => VcsRepositoryFactory::EXCLUDED_BRANCHES],
            $io,
            $config,
            new HttpDownloader($io, $config),
            null,
            null,
            ['git' => FakeGitDriver::class],
        );
        $versions = array_map(static fn (PackageInterface $package): string => $package->getPrettyVersion(), $repository->getPackages());
        sort($versions);

        // exact names and anchored prefixes only: lookalikes and agent branches stay (9999999-dev is the default branch alias)
        self::assertSame(['9999999-dev', 'dev-copilot/fix-tests', 'dev-dependabot', 'dev-feature/renovate-ish', 'dev-main'], $versions);
    }

    /**
     * 404 is what GitHub answers for deleted or private repos, 403 for rate limits and blocked repos
     */
    #[DataProvider('provideGitHubApiFailures')]
    public function testGitHubApiFailuresFailInsteadOfFallingBackToGit(int $status): void
    {
        $downloader = new GitHttpBackendDownloader(sys_get_temp_dir());
        $downloader->interceptor = static fn () => new Response(['url' => 'https://api.github.com/repos/acme/foo'], $status, [], '{"message": "Not Found"}');
        $config = new Config(false);
        // keeps GitHub from looking up a token in the local git config
        $config->merge(['config' => ['github-domains' => [], 'cache-dir' => sys_get_temp_dir().'/packagist-test-cache']]);
        $driver = new GitHubDriver(['url' => 'https://github.com/acme/foo', 'allow-git-fallback' => false], new NullIO(), $config, $downloader, new ProcessExecutor());

        try {
            $driver->initialize();
            self::fail('Expected the GitHub API failure to be thrown');
        } catch (\RuntimeException $e) {
            $apiError = VcsRepositoryFactory::unwrapDisabledGitFallback($e);
        }

        self::assertInstanceOf(TransportException::class, $apiError);
        self::assertSame($status, $apiError->getStatusCode());
        self::assertSame(['https://api.github.com/repos/acme/foo'], array_column($downloader->requests, 'url'));
    }

    /**
     * @return iterable<array{int}>
     */
    public static function provideGitHubApiFailures(): iterable
    {
        yield 'not found' => [404];
        yield 'forbidden' => [403];
    }

    public function testOtherExceptionsAreNotUnwrapped(): void
    {
        $e = new \RuntimeException('Something else', 0, new TransportException('Not found', 404));

        self::assertSame($e, VcsRepositoryFactory::unwrapDisabledGitFallback($e));
    }

    // public IP literal so no DNS lookup happens
    private function createRepository(string $url = 'https://8.8.8.8/acme/foo.git'): VcsRepository
    {
        $io = new NullIO();
        $config = VcsRepositoryFactory::createConfig();

        return VcsRepositoryFactory::create($url, $io, $config, new HttpDownloader($io, $config));
    }
}

/**
 * Mimics a repository full of bot branches without running git
 */
class FakeGitDriver extends GitDriver
{
    public const array BRANCHES = [
        'main', 'copilot/fix-tests', 'feature/renovate-ish', 'dependabot',
        'dependabot/bar', 'dependabot-20260708', 'renovate/foo', 'snyk-fix-1a2b3c', 'deps/2026-10-26', 'deps-bump',
        'gh-readonly-queue/main/pr-1', 'merge-up/1.x', 'mergify/bp/2.x/pr-7', 'backport/1.x/pr-3', 'backport-1001-to-v24', 'release-please--branches--main--components--foo', 'revert-1-main',
        'analysis-0p2Bw4', 'repo-sync/apis/default', 'conductor-abc', 'gh-pages', 'gitattributes',
    ];

    public function initialize(): void
    {
        $this->rootIdentifier = 'main';
        $this->tags = [];
        $this->branches = [];
        foreach (self::BRANCHES as $branch) {
            $this->branches[$branch] = sha1($branch);
        }
    }

    public function getComposerInformation(string $identifier): ?array
    {
        return ['name' => 'acme/foo'];
    }
}
