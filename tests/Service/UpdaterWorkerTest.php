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

namespace App\Tests\Service;

use App\Entity\Job;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Log\AuditLogEventType;
use App\Model\DownloadManager;
use App\Model\PackageManager;
use App\Package\Git\RepositoryNotFoundException;
use App\Package\Git\RepositoryUnavailableException;
use App\Package\Git\UnsupportedRepositoryException;
use App\Package\RepositoryApiCheck;
use App\Package\RepositoryApiChecker;
use App\Package\RepositoryApiState;
use App\Package\Updater;
use App\Service\FallbackGitHubAuthProvider;
use App\Service\Locker;
use App\Service\Scheduler;
use App\Service\UpdaterWorker;
use App\Service\VersionCache;
use App\Tests\IntegrationTestCase;
use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Repository\VcsRepository;
use Composer\Util\HttpDownloader;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Graze\DogStatsD\Client as StatsDClient;
use Psr\Log\NullLogger;
use Seld\Signal\SignalHandler;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class UpdaterWorkerTest extends IntegrationTestCase
{
    // an IP literal host passes the public host check without a DNS lookup, and the stubbed Updater never connects
    private const string REPOSITORY = 'https://8.8.8.8/acme/lib.git';
    private const string REPOSITORY_NOT_FOUND = "Failed to execute git clone --mirror -- '".self::REPOSITORY."' '/tmp/x.git'\n\nremote: Repository not found.\n";
    private const string NO_DRIVER = 'Driver could not be established for package acme/lib ('.self::REPOSITORY.')';

    public function testNoDriverSkipsTheHostForAWeekOnFirstFailure(): void
    {
        $package = $this->storePackage();

        $result = $this->runWorker($package, new \RuntimeException(self::NO_DRIVER));

        self::assertSame(Job::STATUS_FAILED, $result['status']);
        $package = $this->reload($package);
        self::assertFalse($package->isFrozen());
        self::assertNotNull($package->unreachableSince);
        self::assertEqualsWithDelta(new \DateTimeImmutable()->getTimestamp(), $package->unreachableSince->getTimestamp(), 60);
        self::assertGreaterThan(new \DateTimeImmutable('+6 days'), $package->getCrawledAt());
    }

    public function testNoDriverKeepsTheFirstFailureTimeOnRepeats(): void
    {
        $since = new \DateTimeImmutable('-20 days');
        $package = $this->storePackage($since);

        $result = $this->runWorker($package, new \RuntimeException(self::NO_DRIVER));

        self::assertSame(Job::STATUS_FAILED, $result['status']);
        $package = $this->reload($package);
        self::assertFalse($package->isFrozen());
        self::assertSame($since->getTimestamp(), $package->unreachableSince?->getTimestamp());
    }

    public function testNoDriverFreezesThePackageOncePersistent(): void
    {
        $package = $this->storePackage(new \DateTimeImmutable('-29 days'));

        $result = $this->runWorker($package, new \RuntimeException(self::NO_DRIVER));

        self::assertSame(Job::STATUS_PACKAGE_GONE, $result['status']);
        $package = $this->reload($package);
        self::assertSame(PackageFreezeReason::Gone, $package->getFreezeReason());

        $attributes = $this->lastFreezeAuditAttributes();
        self::assertStringStartsWith('no_driver: RuntimeException: Driver could not be established', $attributes['internalReason']);
        self::assertStringContainsString('(unreachable since ', $attributes['internalReason']);
    }

    public function testGoneFailureFreezesImmediately(): void
    {
        $package = $this->storePackage();

        $result = $this->runWorker($package, new \RuntimeException(self::REPOSITORY_NOT_FOUND));

        self::assertSame(Job::STATUS_PACKAGE_GONE, $result['status']);
        self::assertSame(PackageFreezeReason::Gone, $this->reload($package)->getFreezeReason());
        self::assertStringStartsWith('git_remote_repository_not_found: RuntimeException: Failed to execute git clone', $this->lastFreezeAuditAttributes()['internalReason']);
    }

    public function testGitHttpNotFoundFreezesImmediately(): void
    {
        $package = $this->storePackage();

        $result = $this->runWorker($package, new RepositoryNotFoundException('Failed to read '.self::REPOSITORY, 404));

        self::assertSame(Job::STATUS_PACKAGE_GONE, $result['status']);
        self::assertSame(PackageFreezeReason::Gone, $this->reload($package)->getFreezeReason());
        self::assertStringStartsWith('git_http_not_found: App\\Package\\Git\\RepositoryNotFoundException: Failed to read', $this->lastFreezeAuditAttributes()['internalReason']);
    }

    public function testGitHttpAuthPromptSkipsTheHostForAWeekOnFirstFailure(): void
    {
        $package = $this->storePackage();

        $result = $this->runWorker($package, new RepositoryNotFoundException('Failed to read '.self::REPOSITORY, 401));

        self::assertSame(Job::STATUS_FAILED, $result['status']);
        $package = $this->reload($package);
        self::assertFalse($package->isFrozen());
        self::assertNotNull($package->unreachableSince);
        self::assertGreaterThan(new \DateTimeImmutable('+6 days'), $package->getCrawledAt());
    }

    public function testGitHttpAuthPromptFreezesThePackageOncePersistent(): void
    {
        $package = $this->storePackage(new \DateTimeImmutable('-29 days'));

        $result = $this->runWorker($package, new RepositoryNotFoundException('Failed to read '.self::REPOSITORY, 401));

        self::assertSame(Job::STATUS_PACKAGE_GONE, $result['status']);
        self::assertStringContainsString('(unreachable since ', $this->lastFreezeAuditAttributes()['internalReason']);
    }

    public function testGitHttpUnavailableHostIsSkippedButNeverFrozen(): void
    {
        $package = $this->storePackage(new \DateTimeImmutable('-60 days'));

        $result = $this->runWorker($package, new RepositoryUnavailableException('Failed to read '.self::REPOSITORY, 503));

        self::assertSame(Job::STATUS_FAILED, $result['status']);
        $package = $this->reload($package);
        self::assertFalse($package->isFrozen());
        self::assertGreaterThan(new \DateTimeImmutable('+6 days'), $package->getCrawledAt());
    }

    public function testUnsupportedGitServerFailsTheUpdateOnly(): void
    {
        $package = $this->storePackage();

        $result = $this->runWorker($package, new UnsupportedRepositoryException('The server at '.self::REPOSITORY.' only supports the dumb HTTP protocol, which is not supported. Enable smart HTTP on your git server.'));

        self::assertSame(Job::STATUS_FAILED, $result['status']);
        $package = $this->reload($package);
        self::assertFalse($package->isFrozen());
        self::assertNull($package->unreachableSince);
    }

    public function testPrivateHostSkipsTheHostForAWeekOnFirstFailure(): void
    {
        $package = $this->storePackage(repository: 'https://127.0.0.1/acme/private.git');

        $result = $this->runWorker($package, new \LogicException('the update must not be attempted'));

        self::assertSame(Job::STATUS_FAILED, $result['status']);
        $package = $this->reload($package);
        self::assertFalse($package->isFrozen());
        self::assertNotNull($package->unreachableSince);
        self::assertGreaterThan(new \DateTimeImmutable('+6 days'), $package->getCrawledAt());
    }

    public function testPrivateHostFreezesThePackageOncePersistent(): void
    {
        $package = $this->storePackage(new \DateTimeImmutable('-29 days'), 'https://127.0.0.1/acme/private.git');

        $result = $this->runWorker($package, new \LogicException('the update must not be attempted'));

        self::assertSame(Job::STATUS_PACKAGE_GONE, $result['status']);
        self::assertSame(PackageFreezeReason::Gone, $this->reload($package)->getFreezeReason());
        self::assertStringStartsWith('private_host: App\\Package\\PrivateRepositoryHostException: The repository host 127.0.0.1 resolves', $this->lastFreezeAuditAttributes()['internalReason']);
    }

    public function testGoneFailureIsNotFrozenWhenTheApiSaysTheRepositoryExists(): void
    {
        $package = $this->storePackage(new \DateTimeImmutable('-20 days'));
        $before = new \DateTimeImmutable('-1 second');

        $result = $this->runWorker($package, new \RuntimeException(self::REPOSITORY_NOT_FOUND), new RepositoryApiCheck(RepositoryApiState::Exists));

        self::assertSame(Job::STATUS_FAILED, $result['status']);
        self::assertStringContainsString('still exists according to the API', $result['message'] ?? '');
        $package = $this->reload($package);
        self::assertFalse($package->isFrozen());
        self::assertNull($package->unreachableSince);
        self::assertTrue($package->isUpdateFailureNotified());
        self::assertNotNull($package->getCrawledAt());
        self::assertGreaterThanOrEqual($before, $package->getCrawledAt());
        self::assertLessThan(new \DateTimeImmutable('+1 day'), $package->getCrawledAt());
    }

    public function testMaintainersAreMailedOnceWhenGitAndTheApiDisagree(): void
    {
        $maintainer = self::createUser('maintainer', 'maintainer@example.org');
        $package = self::createPackage('acme/lib', self::REPOSITORY, maintainers: [$maintainer]);
        $this->store($maintainer, $package);

        $this->runWorker($package, new \RuntimeException(self::REPOSITORY_NOT_FOUND), new RepositoryApiCheck(RepositoryApiState::Exists));
        $this->runWorker($this->reload($package), new \RuntimeException(self::REPOSITORY_NOT_FOUND), new RepositoryApiCheck(RepositoryApiState::Exists));

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        self::assertNotNull($email);
        $this->assertEmailHeaderSame($email, 'Subject', 'acme/lib failed to update, repository could not be read');
        $this->assertEmailTextBodyContains($email, 'The acme/lib package of which you are a maintainer');
        $this->assertEmailTextBodyContains($email, '/packages/acme/lib');
    }

    public function testPersistentNoDriverIsSkippedForAWeekWhenTheApiSaysTheRepositoryExists(): void
    {
        $package = $this->storePackage(new \DateTimeImmutable('-29 days'));

        $result = $this->runWorker($package, new \RuntimeException(self::NO_DRIVER), new RepositoryApiCheck(RepositoryApiState::Exists));

        self::assertSame(Job::STATUS_FAILED, $result['status']);
        $package = $this->reload($package);
        self::assertFalse($package->isFrozen());
        self::assertGreaterThan(new \DateTimeImmutable('+6 days'), $package->getCrawledAt());
    }

    public function testUnreachableHostIsStillSkippedWhenTheApiSaysTheRepositoryExists(): void
    {
        $package = $this->storePackage();

        $result = $this->runWorker($package, new \RuntimeException(self::NO_DRIVER), new RepositoryApiCheck(RepositoryApiState::Exists));

        self::assertSame(Job::STATUS_FAILED, $result['status']);
        self::assertGreaterThan(new \DateTimeImmutable('+6 days'), $this->reload($package)->getCrawledAt());
    }

    public function testGoneFailureIsSkippedForAWeekWhenTheApiFirstSaysMissing(): void
    {
        $package = $this->storePackage();

        $result = $this->runWorker($package, new \RuntimeException(self::REPOSITORY_NOT_FOUND), new RepositoryApiCheck(RepositoryApiState::Missing, 404));

        self::assertSame(Job::STATUS_FAILED, $result['status']);
        $package = $this->reload($package);
        self::assertFalse($package->isFrozen());
        self::assertNotNull($package->unreachableSince);
        self::assertGreaterThan(new \DateTimeImmutable('+6 days'), $package->getCrawledAt());
    }

    public function testPersistentGoneFailureIsNotFrozenWhenTheApiCheckIsInconclusive(): void
    {
        $since = new \DateTimeImmutable('-40 days');
        $package = $this->storePackage($since);

        $result = $this->runWorker($package, new \RuntimeException(self::REPOSITORY_NOT_FOUND), new RepositoryApiCheck(RepositoryApiState::Unknown));

        self::assertSame(Job::STATUS_FAILED, $result['status']);
        $package = $this->reload($package);
        self::assertFalse($package->isFrozen());
        self::assertSame($since->getTimestamp(), $package->unreachableSince?->getTimestamp());
        self::assertGreaterThan(new \DateTimeImmutable('+6 days'), $package->getCrawledAt());
    }

    public function testApiNotFoundBehindADisabledGitFallbackIsTreatedAsGone(): void
    {
        $package = $this->storePackage();

        $result = $this->runWorker($package, self::disabledGitFallback(404), new RepositoryApiCheck(RepositoryApiState::Missing, 404));

        self::assertSame(Job::STATUS_FAILED, $result['status']);
        $package = $this->reload($package);
        self::assertFalse($package->isFrozen());
        self::assertNotNull($package->unreachableSince);
        self::assertGreaterThan(new \DateTimeImmutable('+6 days'), $package->getCrawledAt());
    }

    public function testOtherApiErrorsBehindADisabledGitFallbackAreRetried(): void
    {
        $package = $this->storePackage();

        $result = $this->runWorker($package, self::disabledGitFallback(403), new RepositoryApiCheck(RepositoryApiState::Missing, 404));

        self::assertSame(Job::STATUS_FAILED, $result['status']);
        $package = $this->reload($package);
        self::assertFalse($package->isFrozen());
        self::assertNull($package->unreachableSince);
        self::assertLessThanOrEqual(new \DateTimeImmutable(), $package->getCrawledAt());
    }

    public function testPackageWithFewDownloadsIsDeletedOnceTheApiKeptSayingMissing(): void
    {
        $package = $this->storePackage(new \DateTimeImmutable('-29 days'));
        $id = $package->getId();

        $result = $this->runWorker($package, new \RuntimeException(self::REPOSITORY_NOT_FOUND), new RepositoryApiCheck(RepositoryApiState::Missing, 451));

        self::assertSame(Job::STATUS_PACKAGE_DELETED, $result['status']);
        $this->getEM()->clear();
        self::assertNull($this->getEM()->getRepository(Package::class)->find($id));

        $logs = self::getService(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log WHERE type = ? ORDER BY id DESC', [AuditLogEventType::PackageDeleted->value]);
        self::assertCount(1, $logs);
        $attributes = json_decode($logs[0]['attributes'], true);
        self::assertStringStartsWith('git_remote_repository_not_found: RuntimeException: ', $attributes['internalReason']);
        self::assertStringEndsWith(', missing according to the API: HTTP 451)', $attributes['internalReason']);
    }

    public function testChangingTheRepositoryClearsUnreachableSince(): void
    {
        $package = $this->storePackage(new \DateTimeImmutable('-20 days'));

        $package->setRepository('https://8.8.4.4/acme/lib.git');

        self::assertNull($package->unreachableSince);
    }

    public function testUnrelatedPackageChangesKeepUnreachableSince(): void
    {
        $since = new \DateTimeImmutable('-20 days');
        $package = $this->storePackage($since);

        $package->setUpdatedAt(new \DateTimeImmutable());
        $package->setAbandoned(true);

        self::assertSame($since, $package->unreachableSince);
    }

    public function testUnfreezeClearsUnreachableSince(): void
    {
        $package = $this->storePackage(new \DateTimeImmutable('-40 days'));
        $package->freeze(PackageFreezeReason::Gone);

        $package->unfreeze();

        self::assertNull($package->unreachableSince);
    }

    /**
     * What the Bitbucket driver throws when its API request fails and the git fallback is disabled
     */
    private static function disabledGitFallback(int $status): \RuntimeException
    {
        $apiError = new TransportException('The "https://api.bitbucket.org/2.0/repositories/acme/lib?fields=-project%2C-owner" file could not be downloaded (HTTP/2 '.$status.' )', $status);
        $apiError->setStatusCode($status);

        return new \RuntimeException('Fallback to git driver disabled', 0, $apiError);
    }

    private function storePackage(?\DateTimeImmutable $unreachableSince = null, string $repository = self::REPOSITORY): Package
    {
        $package = self::createPackage('acme/lib', $repository);
        $package->unreachableSince = $unreachableSince;
        $this->store($package);

        return $package;
    }

    private function reload(Package $package): Package
    {
        $this->getEM()->clear();
        $reloaded = $this->getEM()->getRepository(Package::class)->find($package->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastFreezeAuditAttributes(): array
    {
        $logs = self::getService(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log WHERE type = ? ORDER BY id DESC', [AuditLogEventType::PackageFrozen->value]);
        self::assertCount(1, $logs);

        return json_decode($logs[0]['attributes'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function runWorker(Package $package, \Throwable $failure, ?RepositoryApiCheck $apiCheck = null): array
    {
        $apiChecker = $this->createStub(RepositoryApiChecker::class);
        $apiChecker->method('check')->willReturn($apiCheck);

        // not a PHPUnit double: Updater::__destruct() would restore_error_handler() and pop PHPUnit's handler
        $updater = new class($failure) extends Updater {
            public function __construct(private readonly \Throwable $failure)
            {
            }

            public function __destruct()
            {
            }

            public function update(IOInterface $io, Config $config, HttpDownloader $httpDownloader, Package $package, VcsRepository $repository, int $flags = 0, ?array $existingVersions = null, ?VersionCache $versionCache = null): Package
            {
                throw $this->failure;
            }
        };

        $doctrine = self::getService(ManagerRegistry::class);
        $worker = new UpdaterWorker(
            new NullLogger(),
            $doctrine,
            $updater,
            new Locker($doctrine),
            self::getService(Scheduler::class),
            self::getService(PackageManager::class),
            self::getService(DownloadManager::class),
            $this->createStub(StatsDClient::class),
            self::getService(FallbackGitHubAuthProvider::class),
            new ArrayAdapter(),
            '',
            $apiChecker,
        );

        $job = new Job('update-job', 'package:updates', ['id' => $package->getId(), 'update_equal_refs' => false, 'delete_before' => false, 'force_dump' => false, 'source' => 'test']);

        return $worker->process($job, SignalHandler::create());
    }
}
