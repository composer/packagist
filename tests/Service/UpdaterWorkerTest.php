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
use App\Package\HttpGitShadow;
use App\Package\Updater;
use App\Service\FallbackGitHubAuthProvider;
use App\Service\Locker;
use App\Service\Scheduler;
use App\Service\UpdaterWorker;
use App\Service\VersionCache;
use App\Tests\IntegrationTestCase;
use Composer\Config;
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

        $result = $this->runWorker($package, new \RuntimeException("Failed to execute git clone --mirror -- '".self::REPOSITORY."' '/tmp/x.git'\n\nremote: Repository not found.\n"));

        self::assertSame(Job::STATUS_PACKAGE_GONE, $result['status']);
        self::assertSame(PackageFreezeReason::Gone, $this->reload($package)->getFreezeReason());
        self::assertStringStartsWith('git_remote_repository_not_found: RuntimeException: Failed to execute git clone', $this->lastFreezeAuditAttributes()['internalReason']);
    }

    public function testSuccessfulUpdateClearsUnreachableSince(): void
    {
        $package = $this->storePackage(new \DateTimeImmutable('-20 days'));

        $package->setUpdatedAt(new \DateTimeImmutable());

        self::assertNull($package->unreachableSince);
    }

    public function testUnfreezeClearsUnreachableSince(): void
    {
        $package = $this->storePackage(new \DateTimeImmutable('-40 days'));
        $package->freeze(PackageFreezeReason::Gone);

        $package->unfreeze();

        self::assertNull($package->unreachableSince);
    }

    private function storePackage(?\DateTimeImmutable $unreachableSince = null): Package
    {
        $package = self::createPackage('acme/lib', self::REPOSITORY);
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
    private function runWorker(Package $package, \Throwable $failure): array
    {
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
            self::getService(HttpGitShadow::class),
        );

        $job = new Job('update-job', 'package:updates', ['id' => $package->getId(), 'update_equal_refs' => false, 'delete_before' => false, 'force_dump' => false, 'source' => 'test']);

        return $worker->process($job, SignalHandler::create());
    }
}
