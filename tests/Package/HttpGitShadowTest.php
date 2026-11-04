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

use App\Entity\Package;
use App\Package\HttpGitShadow;
use App\Package\Vcs\HttpGitDriver;
use App\Service\VersionCache;
use App\Tests\Package\Git\GitHttpBackendDownloader;
use App\Tests\Package\Git\GitRepositoryBuilder;
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Repository\Vcs\GitDriver;
use Composer\Repository\Vcs\GitHubDriver;
use Composer\Util\ProcessExecutor;
use Graze\DogStatsD\Client as StatsDClient;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;

class HttpGitShadowTest extends TestCase
{
    private static string $projectRoot;
    /** @var array{v1: string, main: string, feature: string} */
    private static array $commits;

    private TestHandler $logs;
    private HttpGitShadow $shadow;

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = GitRepositoryBuilder::createTempDir('http-git-shadow-test');

        $builder = new GitRepositoryBuilder(self::$projectRoot.'/work');
        $v1 = $builder->commit(['composer.json' => '{"name":"acme/foo"}']);
        $builder->tag('v1.0.0', true);
        $main = $builder->commit(['composer.json' => '{"name":"acme/foo","description":"main"}']);
        $builder->checkout('feature', true);
        $feature = $builder->commit(['composer.json' => '{"name":"acme/foo","description":"feature"}']);
        $builder->checkout('main');
        $builder->publish(self::$projectRoot, 'foo.git');
        self::$commits = ['v1' => $v1, 'main' => $main, 'feature' => $feature];

        // same history, but the feature branch moved and an extra tag points to a commit with another composer.json
        $builder->checkout('feature');
        $builder->commit(['composer.json' => '{"name":"acme/foo","description":"moved"}']);
        $builder->checkout('main');
        $builder->commit(['composer.json' => '{"name":"acme/foo","description":"next"}']);
        $builder->tag('v2.0.0');
        $builder->git('reset', '-q', '--hard', $main);
        $builder->publish(self::$projectRoot, 'diverged.git');
    }

    public static function tearDownAfterClass(): void
    {
        new Filesystem()->remove(self::$projectRoot);
    }

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
        $this->shadow = new HttpGitShadow(new Logger('test', [$this->logs]), $this->createStub(StatsDClient::class), new ArrayAdapter(), 100);
    }

    public function testMatchingRepositoryHasNoDifferences(): void
    {
        $downloader = new GitHttpBackendDownloader(self::$projectRoot);

        self::assertSame([], HttpGitShadow::diff($this->createReference(), $this->createHttpDriver($downloader, 'foo.git', []), []));
    }

    public function testReportsDifferences(): void
    {
        $downloader = new GitHttpBackendDownloader(self::$projectRoot);
        $differences = HttpGitShadow::diff($this->createReference(), $this->createHttpDriver($downloader, 'diverged.git', []), []);

        self::assertCount(2, $differences);
        self::assertStringStartsWith('tag v2.0.0: missing vs ', $differences[0]);
        self::assertStringStartsWith('branch feature: '.self::$commits['feature'].' vs ', $differences[1]);
    }

    public function testReportsComposerJsonDifferences(): void
    {
        $reference = $this->createReference();
        // a stale cache entry in the reference driver stands in for a misread composer.json
        $infoCache = new \ReflectionProperty($reference, 'infoCache');
        $infoCache->setValue($reference, [self::$commits['feature'] => ['name' => 'acme/stale']]);

        $downloader = new GitHttpBackendDownloader(self::$projectRoot);

        self::assertSame(['composer.json of branch feature ('.self::$commits['feature'].')'], HttpGitShadow::diff($reference, $this->createHttpDriver($downloader, 'foo.git', []), []));
    }

    public function testKnownReferencesAreNotFetched(): void
    {
        $downloader = new GitHttpBackendDownloader(self::$projectRoot);
        $known = array_values(self::$commits);

        self::assertSame([], HttpGitShadow::diff($this->createReference(), $this->createHttpDriver($downloader, 'foo.git', $known), $known));
        self::assertSame(['GET', 'POST'], array_column($downloader->requests, 'method'), 'only the ref listing is expected');
    }

    public function testLogsMismatchesOncePerDistinctResult(): void
    {
        $downloader = new GitHttpBackendDownloader(self::$projectRoot);
        $package = $this->createPackage();

        $this->shadow->compareWithUpdate($package, $this->createReference('diverged.git'), null, $this->createConfig(), $downloader);
        $this->shadow->compareWithUpdate($package, $this->createReference('diverged.git'), null, $this->createConfig(), $downloader);

        self::assertCount(1, $this->logs->getRecords());
        $record = $this->logs->getRecords()[0];
        self::assertSame(Level::Error, $record->level);
        self::assertSame(2, $record->context['differenceCount']);

        $this->shadow->compareWithUpdate($package, $this->createReference('foo.git'), null, $this->createConfig(), $downloader);
        $this->shadow->compareWithUpdate($package, $this->createReference('foo.git'), null, $this->createConfig(), $downloader);

        self::assertCount(2, $this->logs->getRecords());
        self::assertSame(Level::Info, $this->logs->getRecords()[1]->level);
    }

    public function testLogsShadowFailures(): void
    {
        $this->shadow->compareWithUpdate($this->createPackage(), $this->createReference('missing.git'), null, $this->createConfig(), new GitHttpBackendDownloader(self::$projectRoot));

        self::assertTrue($this->logs->hasErrorRecords());
        self::assertStringStartsWith('HttpGitDriver failed: App\Package\Git\RepositoryNotFoundException', $this->logs->getRecords()[0]->context['differences'][0]);
    }

    public function testComparesTheGitFallbackOfApiDrivers(): void
    {
        $driver = $this->createStub(GitHubDriver::class);
        new \ReflectionProperty(GitHubDriver::class, 'gitDriver')->setValue($driver, $this->createReference('diverged.git'));

        $this->shadow->compareWithUpdate($this->createPackage(), $driver, null, $this->createConfig(), new GitHttpBackendDownloader(self::$projectRoot));

        self::assertTrue($this->logs->hasErrorRecords());
    }

    public function testSkipsRepositoriesNotReadWithTheGitBinary(): void
    {
        $downloader = new GitHttpBackendDownloader(self::$projectRoot);

        $this->shadow->compareWithUpdate($this->createPackage(), $this->createStub(GitHubDriver::class), null, $this->createConfig(), $downloader);
        $this->shadow->compareWithUpdate($this->createPackage(), $this->createHttpDriver($downloader, 'diverged.git', null), null, $this->createConfig(), $downloader);

        self::assertSame([], $downloader->requests);
        self::assertSame([], $this->logs->getRecords());
    }

    public function testOnlyComparesTheSampledUpdates(): void
    {
        $downloader = new GitHttpBackendDownloader(self::$projectRoot);
        $shadow = new HttpGitShadow(new Logger('test', [$this->logs]), $this->createStub(StatsDClient::class), new ArrayAdapter(), 0);

        $shadow->compareWithUpdate($this->createPackage(), $this->createReference('diverged.git'), null, $this->createConfig(), $downloader);

        self::assertSame([], $downloader->requests);
        self::assertSame([], $this->logs->getRecords());
    }

    public function testUsesTheVersionCacheKnownReferences(): void
    {
        $downloader = new GitHttpBackendDownloader(self::$projectRoot);
        $versions = [];
        foreach (['v1.0.0' => 'v1', 'dev-main' => 'main'] as $version => $commit) {
            $versions[] = ['version' => $version, 'normalizedVersion' => $version, 'source' => ['type' => 'git', 'url' => '', 'reference' => self::$commits[$commit]], 'defaultBranch' => 0];
        }
        $versionCache = new VersionCache($this->createPackage(), $versions, [self::$commits['feature']]);

        $this->shadow->compareWithUpdate($this->createPackage(), $this->createReference('foo.git'), $versionCache, $this->createConfig(), $downloader);

        self::assertSame(['GET', 'POST'], array_column($downloader->requests, 'method'));
        self::assertSame([], $this->logs->getRecords());
    }

    /**
     * Reads foo.git with the git binary, reporting $url as its URL like a remote clone would
     */
    private function createReference(?string $path = null): GitDriver
    {
        $driver = new GitDriver(['url' => self::$projectRoot.'/foo.git'], new NullIO(), $this->createConfig(), new GitHttpBackendDownloader(self::$projectRoot), new ProcessExecutor());
        $driver->initialize();
        if ($path !== null) {
            new \ReflectionProperty(GitDriver::class, 'url')->setValue($driver, GitHttpBackendDownloader::BASE_URL.$path);
        }

        return $driver;
    }

    /**
     * @param ?list<string> $knownReferences
     */
    private function createHttpDriver(GitHttpBackendDownloader $downloader, string $path, ?array $knownReferences): HttpGitDriver
    {
        $repoConfig = ['url' => GitHttpBackendDownloader::BASE_URL.$path];
        if ($knownReferences !== null) {
            $repoConfig['known-references'] = $knownReferences;
        }

        return new HttpGitDriver($repoConfig, new NullIO(), $this->createConfig(), $downloader, new ProcessExecutor());
    }

    private function createPackage(): Package
    {
        $package = new Package();
        $package->setName('acme/foo');
        new \ReflectionProperty($package, 'id')->setValue($package, 42);

        return $package;
    }

    private function createConfig(): Config
    {
        $config = new Config(false);
        $config->merge(['config' => ['cache-dir' => self::$projectRoot.'/cache', 'home' => self::$projectRoot.'/home']]);

        return $config;
    }
}
