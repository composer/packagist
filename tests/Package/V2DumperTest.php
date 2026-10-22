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

use App\Audit\VersionDeletionReason;
use App\Entity\FilterListEntry;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\SecurityAdvisory;
use App\Entity\Version;
use App\FilterList\Dump\FilterListDumperProvider;
use App\FilterList\FilterLists;
use App\FilterList\FilterSources;
use App\FilterList\RemoteFilterListEntry;
use App\Model\ProviderManager;
use App\Package\V2Dumper;
use App\Package\V2DumpOutcome;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use App\Service\CdnClient;
use App\Service\Locker;
use App\Service\ReplicaClient;
use App\Tests\IntegrationTestCase;
use Composer\Semver\VersionParser;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Graze\DogStatsD\Client as StatsDClient;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Predis\Client as RedisClient;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class V2DumperTest extends IntegrationTestCase
{
    private string $webDir;
    private string $buildDir;
    private V2Dumper $dumper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->webDir = sys_get_temp_dir().'/v2dumper_web_'.uniqid('', true);
        $this->buildDir = sys_get_temp_dir().'/v2dumper_build_'.uniqid('', true);
        mkdir($this->webDir, 0o777, true);
        mkdir($this->buildDir, 0o777, true);

        // Return a filemtime that does NOT end with '0000' so the Redis counter branch is skipped
        $cdnClient = $this->createStub(CdnClient::class);
        $cdnClient->method('uploadMetadata')->willReturn(16062106090001);
        $cdnClient->method('purgeMetadataCache')->willReturn(true);

        $filterListDumperProvider = static::getContainer()->get(FilterListDumperProvider::class);

        $this->dumper = new V2Dumper(
            static::getContainer()->get(ManagerRegistry::class),
            new Filesystem(),
            $this->createStub(RedisClient::class),
            $this->webDir,
            $this->buildDir,
            $this->createStub(StatsDClient::class),
            $this->createStub(ProviderManager::class),
            new Logger('test'),
            $cdnClient,
            $this->createStub(ReplicaClient::class),
            static::getContainer()->get(UrlGeneratorInterface::class),
            new MockHttpClient(),
            $filterListDumperProvider,
            static::getContainer()->get(Locker::class),
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        new Filesystem()->remove($this->webDir);
        new Filesystem()->remove($this->buildDir);
    }

    public function testDumpRoot(): void
    {
        $this->dumper->dumpRoot();

        $packagesJson = $this->webDir.'/packages.json';
        $this->assertFileExists($packagesJson);

        $data = json_decode((string) file_get_contents($packagesJson), true);
        $this->assertTrue($data['filter']['metadata']);
        $this->assertSame(['enabled' => true], $data['filter']['lists']['malware']);
    }

    public function testDumpPackageMetadata(): void
    {
        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $version = $this->createVersion($package, '1.0.0');
        $devVersion = $this->createVersion($package, 'dev-main');
        $this->store($package, $version, $devVersion);

        $this->dumper->dump([$package->getId()], force: true);

        $releaseFile = $this->buildDir.'/p2/acme/package.json';
        $this->assertFileExists($releaseFile);

        $data = json_decode((string) file_get_contents($releaseFile), true);
        $this->assertSame('composer/2.0', $data['minified']);
        $this->assertNotEmpty($data['packages']['acme/package']);
        $this->assertSame([], $data['security-advisories']);
        $this->assertArrayNotHasKey('filter', $data);

        // stable releases use createdAt as the published-time (cooldown reference)
        $this->assertSame(
            $version->getCreatedAt()->format('Y-m-d\TH:i:sP'),
            $data['packages']['acme/package'][0]['published-time'],
        );

        $devFile = $this->buildDir.'/p2/acme/package~dev.json';
        $this->assertFileExists($devFile);

        $devData = json_decode((string) file_get_contents($devFile), true);
        $this->assertArrayNotHasKey('security-advisories', $devData);

        // dev versions use updatedAt as the published-time so each commit restarts the cooldown
        $this->assertSame(
            $devVersion->getUpdatedAt()->format('Y-m-d\TH:i:sP'),
            $devData['packages']['acme/package'][0]['published-time'],
        );
    }

    public function testDumpSecurityAdvisories(): void
    {
        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $version = $this->createVersion($package, '1.0.0');
        $this->store($package, $version);

        $remote = new RemoteSecurityAdvisory(
            'GHSA-test-1234-5678',
            'Test advisory',
            'acme/package',
            '>=1.0.0,<2.0.0',
            'https://example.com/advisory',
            null,
            new \DateTimeImmutable('2024-01-01'),
            'https://packagist.org',
            [],
            'test-source',
            null,
        );
        $advisory = new SecurityAdvisory($remote, 'test-source');
        $this->store($advisory);

        $this->dumper->dump([$package->getId()], force: true);

        $releaseFile = $this->buildDir.'/p2/acme/package.json';
        $this->assertFileExists($releaseFile);

        $data = json_decode((string) file_get_contents($releaseFile), true);
        $this->assertCount(1, $data['security-advisories']);
        $entry = $data['security-advisories'][0];
        $this->assertNotEmpty($entry['advisoryId']);
        $this->assertSame('>=1.0.0,<2.0.0', $entry['affectedVersions']);
    }

    public function testDumpFilterEntries(): void
    {
        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $version = $this->createVersion($package, '1.0.0');
        $this->store($package, $version);

        $remote = new RemoteFilterListEntry(
            'acme/package',
            '1.0.0',
            FilterLists::MALWARE,
            null,
            'malware',
            FilterSources::AIKIDO,
        );
        $filterEntry = FilterListEntry::fromRemote($remote);
        $this->store($filterEntry);

        $this->dumper->dump([$package->getId()], force: true);

        $releaseFile = $this->buildDir.'/p2/acme/package.json';
        $this->assertFileExists($releaseFile);

        $data = json_decode((string) file_get_contents($releaseFile), true);
        $this->assertArrayHasKey('filter', $data);
        $this->assertArrayHasKey('malware', $data['filter']);
        $filterList = $data['filter']['malware'];
        $this->assertNotEmpty($filterList);
        $this->assertSame('1.0.0', $filterList[0]['constraint']);
        $this->assertSame('malware', $filterList[0]['reason']);
        $this->assertSame(FilterSources::AIKIDO->value, $filterList[0]['source']);
    }

    public function testSpamFrozenPackageIsSkipped(): void
    {
        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $package->freeze(PackageFreezeReason::Spam);
        $version = $this->createVersion($package, '1.0.0');
        $this->store($package, $version);

        $this->dumper->dump([$package->getId()], force: true);

        $releaseFile = $this->buildDir.'/p2/acme/package.json';
        $this->assertFileDoesNotExist($releaseFile);
    }

    public function testMalwareFrozenPackageIsSkipped(): void
    {
        $package = self::createPackage('acme/malware-pkg', 'https://example.com/acme/malware-pkg');
        $package->freeze(PackageFreezeReason::Malware);
        $version = $this->createVersion($package, '1.0.0');
        $this->store($package, $version);

        $this->dumper->dump([$package->getId()], force: true);

        $this->assertFileDoesNotExist($this->buildDir.'/p2/acme/malware-pkg.json');
    }

    public function testTemporarilyFrozenPackageStillKeepsMetadata(): void
    {
        $package = self::createPackage('acme/temp-pkg', 'https://example.com/acme/temp-pkg');
        $package->freeze(PackageFreezeReason::Temporary);
        $version = $this->createVersion($package, '1.0.0');
        $this->store($package, $version);

        $this->dumper->dump([$package->getId()], force: true);

        // A temporary freeze keeps the package's metadata served; it is just not updated.
        $this->assertFileExists($this->buildDir.'/p2/acme/temp-pkg.json');
    }

    public function testSoftDeletedVersionIsExcludedFromMetadata(): void
    {
        $package = self::createPackage('acme/pkg-sd', 'https://example.com/acme/pkg-sd');
        $kept = $this->createVersion($package, '1.0.0');
        $deleted = $this->createVersion($package, '1.1.0');
        $deleted->setSoftDeletedAt(new \DateTimeImmutable());
        $deleted->setDeletionReason(VersionDeletionReason::DeletedByMaintainer);
        $this->store($package, $kept, $deleted);

        $this->dumper->dump([$package->getId()], force: true);

        $releaseFile = $this->buildDir.'/p2/acme/pkg-sd.json';
        $this->assertFileExists($releaseFile);

        $data = json_decode((string) file_get_contents($releaseFile), true);
        $entries = $data['packages']['acme/pkg-sd'];
        $versionsInDump = array_map(static fn (array $row): string => $row['version'] ?? '', $entries);

        $this->assertContains('1.0.0', $versionsInDump);
        $this->assertNotContains('1.1.0', $versionsInDump, 'soft-deleted version must not appear in metadata dump');
    }

    public function testDumpRootAdvertisesFilterSummaryUrl(): void
    {
        $this->dumper->dumpRoot();

        $rootFile = $this->webDir.'/packages.json';
        $this->assertFileExists($rootFile);

        $data = json_decode((string) file_get_contents($rootFile), true);
        $this->assertArrayHasKey('filter', $data);
        $this->assertArrayHasKey('summary-url', $data['filter']);
        $this->assertStringEndsWith('/lists/all/summary.json', $data['filter']['summary-url']);
    }

    public function testCdnTimeoutsAreCountedByOpAndReason(): void
    {
        $cdnClient = $this->createStub(CdnClient::class);
        $cdnClient->method('uploadMetadata')->willThrowException(new TimeoutException('Idle timeout reached for "https://storage.example/p2/acme/package.json".'));
        $increments = [];
        $this->rebuildDumper($cdnClient, $this->recordingStatsd($increments));

        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $this->store($package, $this->createVersion($package, '1.0.0'));

        $this->dumper->dump([$package->getId()], force: true);

        self::assertSame([['op' => 'upload', 'worker' => '0', 'reason' => 'timeout']], self::tagsOf($increments, 'packagist.metadata_dump.cdn_failure'));
    }

    public function testAFailingPackageStaysStaleWithoutHoldingBackTheRest(): void
    {
        $this->primeBuildDir();
        $cdnClient = $this->createStub(CdnClient::class);
        $cdnClient->method('uploadMetadata')->willReturnCallback(static function (string $path): int {
            if (str_starts_with($path, 'p2/acme/broken')) {
                throw new \RuntimeException('Unexpected response from the CDN: 500');
            }

            return 16062106090001;
        });
        $cdnClient->method('purgeMetadataCache')->willReturn(true);
        $this->rebuildDumper($cdnClient);

        $broken = self::createPackage('acme/broken', 'https://example.com/acme/broken');
        $healthy = self::createPackage('acme/healthy', 'https://example.com/acme/healthy');
        $this->store($broken, $this->createVersion($broken, '1.0.0'), $healthy, $this->createVersion($healthy, '1.0.0'));
        $this->requestDumpAt($broken->getId(), '-1 minute');
        $this->requestDumpAt($healthy->getId(), '-1 minute');

        $result = $this->dumper->dump([$broken->getId(), $healthy->getId()]);
        self::assertSame(V2DumpOutcome::SomeFailed, $result->outcome);
        self::assertSame([$broken->getId()], $result->leftStale);

        $stale = $this->stalePackageIds();
        self::assertContains($broken->getId(), $stale, 'the failed package must be retried next run');
        self::assertNotContains($healthy->getId(), $stale);
        self::assertFileExists($this->buildDir.'/p2/acme/healthy.json');
    }

    public function testAnUnreachableCdnAbortsTheRunButKeepsWhatWasDumped(): void
    {
        $this->primeBuildDir();
        $uploads = 0;
        $cdnClient = $this->createStub(CdnClient::class);
        // each package uploads its tags and its ~dev file, so the third upload is the second package's first
        $cdnClient->method('uploadMetadata')->willReturnCallback(static function () use (&$uploads): int {
            if (++$uploads > 2) {
                throw new TimeoutException('Idle timeout reached for "https://storage.example/p2/x.json".');
            }

            return 16062106090001;
        });
        $cdnClient->method('purgeMetadataCache')->willReturn(true);
        $logs = [];
        $this->rebuildDumper($cdnClient, logger: self::capturingLogger($logs));

        $ids = [];
        foreach (['acme/first', 'acme/second', 'acme/third'] as $name) {
            $package = self::createPackage($name, 'https://example.com/'.$name);
            $this->store($package, $this->createVersion($package, '1.0.0'));
            $this->requestDumpAt($package->getId(), '-1 minute');
            $ids[] = $package->getId();
        }

        $result = $this->dumper->dump($ids);
        self::assertSame(V2DumpOutcome::Aborted, $result->outcome);
        self::assertSame([$ids[1], $ids[2]], $result->leftStale, 'the package that aborted and the one never reached');

        self::assertCount(2, array_intersect($ids, $this->stalePackageIds()), 'only the package dumped before the failure is recorded');
        self::assertCount(1, array_filter($logs, static fn (string $log): bool => str_starts_with($log, 'CDN unreachable, aborting v2 metadata dump run')));
    }

    public function testASlowlyAppliedPurgeIsRecheckedOnceAfterSettling(): void
    {
        $fetches = [];
        $logs = [];
        $this->rebuildDumper($this->verifyingCdnClient(function (string $path) use (&$fetches): string {
            $fetches[] = $path;

            return \count(array_keys($fetches, $path, true)) === 1 ? 'stale' : $this->localContent($path);
        }), logger: self::capturingLogger($logs));

        $packageId = $this->storeMarkedPackage('acme/package');
        $this->dumper->dump([$packageId]);

        self::assertSame(['p2/acme/package.json', 'p2/acme/package~dev.json', 'p2/acme/package.json', 'p2/acme/package~dev.json'], $fetches, 'all re-checks wait for one shared settle delay');
        self::assertCount(2, self::logsStartingWith($logs, 'Cache purge worked now for '));
        self::assertSame([], self::logsStartingWith($logs, 'Mismatch still present'));
    }

    public function testAFileThatStaysStaleOnTheCdnDoesNotHoldBackTheOthers(): void
    {
        $fetches = [];
        $logs = [];
        $increments = [];
        $this->rebuildDumper($this->verifyingCdnClient(function (string $path) use (&$fetches): string {
            $fetches[$path] = ($fetches[$path] ?? 0) + 1;

            return str_starts_with($path, 'p2/acme/broken') ? 'stale' : $this->localContent($path);
        }), $this->recordingStatsd($increments), self::capturingLogger($logs));

        $broken = $this->storeMarkedPackage('acme/broken');
        $healthy = $this->storeMarkedPackage('acme/healthy');
        $this->dumper->dump([$broken, $healthy]);

        ksort($fetches);
        self::assertSame(['p2/acme/broken.json' => 2, 'p2/acme/broken~dev.json' => 2, 'p2/acme/healthy.json' => 1, 'p2/acme/healthy~dev.json' => 1], $fetches);
        self::assertCount(2, self::logsStartingWith($logs, 'Mismatch still present after another cache purge: p2/acme/broken'));
        self::assertSame([['reason' => 'still_stale'], ['reason' => 'still_stale']], self::tagsOf($increments, 'packagist.metadata_cdn_purge_fail'));
        self::assertSame([], array_intersect([$broken, $healthy], $this->stalePackageIds()), 'storage has the right content, so both count as dumped');
    }

    public function testARejectedPurgeDuringVerificationDoesNotHoldBackTheOthers(): void
    {
        $verifying = false;
        $fetches = [];
        $logs = [];
        $increments = [];
        $this->rebuildDumper($this->verifyingCdnClient(
            function (string $path) use (&$verifying, &$fetches): string {
                $verifying = true;
                $fetches[$path] = ($fetches[$path] ?? 0) + 1;

                return $path === 'p2/acme/broken.json' ? 'stale' : $this->localContent($path);
            },
            static function (string $path) use (&$verifying): bool {
                return !$verifying || $path !== 'p2/acme/broken.json';
            },
        ), $this->recordingStatsd($increments), self::capturingLogger($logs));

        $broken = $this->storeMarkedPackage('acme/broken');
        $healthy = $this->storeMarkedPackage('acme/healthy');
        $result = $this->dumper->dump([$broken, $healthy]);

        self::assertSame(V2DumpOutcome::Complete, $result->outcome);
        ksort($fetches);
        self::assertSame(['p2/acme/broken.json' => 1, 'p2/acme/broken~dev.json' => 1, 'p2/acme/healthy.json' => 1, 'p2/acme/healthy~dev.json' => 1], $fetches);
        self::assertCount(1, self::logsStartingWith($logs, 'Failed verifying CDN file: p2/acme/broken.json'));
        self::assertSame([['reason' => 'verify_error']], self::tagsOf($increments, 'packagist.metadata_cdn_purge_fail'));
        self::assertSame([], array_intersect([$broken, $healthy], $this->stalePackageIds()));
    }

    public function testAnUnreachableCdnStopsVerificationButKeepsTheRunsProgress(): void
    {
        $fetches = [];
        $logs = [];
        $this->rebuildDumper($this->verifyingCdnClient(static function (string $path) use (&$fetches): string {
            $fetches[] = $path;

            throw new TransportException('Connection refused for "https://cdn.example/'.$path.'".');
        }), logger: self::capturingLogger($logs));

        $first = $this->storeMarkedPackage('acme/first');
        $second = $this->storeMarkedPackage('acme/second');
        $this->dumper->dump([$first, $second]);

        self::assertCount(1, array_unique($fetches), 'the files after the unreachable one are not tried');
        self::assertCount(1, self::logsStartingWith($logs, 'CDN unreachable, skipping verification of the remaining files'));
        self::assertSame([], array_intersect([$first, $second], $this->stalePackageIds()));
    }

    public function testAFailedFullPurgeKeepsTheRunsProgress(): void
    {
        $logs = [];
        $cdnClient = $this->verifyingCdnClient(
            fn (string $path): string => $this->localContent($path),
            static function (string $path): bool {
                if ($path === 'p2/*') {
                    throw new TransportException('Idle timeout reached for "https://api.bunny.net/purge".');
                }

                return true;
            },
        );
        $this->rebuildDumper($cdnClient, logger: self::capturingLogger($logs));

        $packageId = $this->storeMarkedPackage('acme/package');
        // a queue above 150 ids switches to one full purge at the end; ids that no longer exist are skipped
        $ids = [$packageId, ...range($packageId + 1000, $packageId + 1150)];
        $this->dumper->dump($ids);

        self::assertCount(1, self::logsStartingWith($logs, 'Failed purging the complete CDN metadata cache'));
        self::assertNotContains($packageId, $this->stalePackageIds());
    }

    public function testANormalDumpRunClearsStaleness(): void
    {
        $this->primeBuildDir();
        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $this->store($package, $this->createVersion($package, '1.0.0'));
        $packageId = $package->getId();
        $this->requestDumpAt($packageId, '-1 minute');

        self::assertContains($packageId, $this->stalePackageIds(), 'a marked package must be selected for dumping');

        $this->dumper->dump([$packageId]);

        self::assertNotContains($packageId, $this->stalePackageIds(), 'a completed dump must clear the package from the stale set');
    }

    public function testAMarkInTheSameSecondAsTheDumpIsRedumpedOnceThenConverges(): void
    {
        // Both columns hold whole seconds, so a mark stamped in the same second as the dumper's dump
        // time may have landed before or after hydration. The >= resolves that towards re-dumping,
        // which costs one content comparison rather than risking a lost change.
        $this->primeBuildDir();
        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $this->store($package, $this->createVersion($package, '1.0.0'));
        $packageId = $package->getId();
        $this->requestDumpAt($packageId, 'now');

        $this->dumper->dump([$packageId]);
        self::assertContains($packageId, $this->stalePackageIds(), 'a same-second mark must be re-dumped rather than assumed covered');

        // The ambiguity only lasts as long as that one second: the next run to start in a later second
        // records a strictly greater dump time and clears it. Re-stamping the request one second back
        // stands in for the clock advancing, so the test does not have to sleep for it.
        $this->requestDumpAt($packageId, '-1 second');
        $this->dumper->dump([$packageId]);
        self::assertNotContains($packageId, $this->stalePackageIds(), 'the ambiguity must settle within a second, not repeat forever');
    }

    public function testAMarkLandingDuringADumpRunSurvivesIt(): void
    {
        // dumpedAtV2 is recorded for the whole run only at the very end, so anything marking the package
        // in that window used to have its mark overwritten and lost. Fire a mark from inside the CDN
        // upload, which happens exactly in that window, and assert the package stays stale.
        $this->primeBuildDir();
        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $this->store($package, $this->createVersion($package, '1.0.0'));
        $packageId = $package->getId();
        // Unambiguously in the past, so the run below clears it and only the mid-run mark can keep the
        // package stale. markForDump() here would land in the dumper's $dumpTime second, which the >=
        // rule counts as stale on its own, and the assertion would hold even if the hook never fired.
        $this->requestDumpAt($packageId, '-1 minute');

        $conn = self::getEM()->getConnection();
        $this->rebuildDumperWithUploadHook(static function () use ($conn, $packageId): void {
            $conn->executeStatement(
                'UPDATE package SET dumpRequestedAt = :now WHERE id = :id',
                ['now' => date('Y-m-d H:i:s'), 'id' => $packageId]
            );
        });

        $this->dumper->dump([$packageId]);

        self::assertContains($packageId, $this->stalePackageIds(), 'a mark landing mid-run must survive the dump run that did not include it');
    }

    public function testUnchangedContentIsNotRewrittenForAnOrdinaryMark(): void
    {
        // An ordinary markForDump() must not bypass the content comparison: every package the stale
        // query hands us is "requested", so honouring that would turn every dump into a real CDN write.
        // The deliberate force path is covered by testForceDumpRewritesUnchangedContent().
        $this->primeBuildDir();
        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $this->store($package, $this->createVersion($package, '1.0.0'));
        $packageId = $package->getId();
        $this->requestDumpAt($packageId, '-1 minute');

        $uploads = 0;
        $this->rebuildDumperWithUploadHook(static function () use (&$uploads): void {
            $uploads++;
        });

        $this->dumper->dump([$packageId]);
        self::assertSame(2, $uploads, 'the first dump writes both the release and the ~dev file');

        // second pass with nothing marked and byte-identical content must early-return
        $this->dumper->dump([$packageId]);
        self::assertSame(2, $uploads, 'an unrequested package with unchanged content must not be rewritten');

        // ... and so must a freshly marked one, as long as the content really is unchanged
        $this->requestDumpAt($packageId, 'now');

        $this->dumper->dump([$packageId]);
        self::assertSame(2, $uploads, 'a marked package with unchanged content must still not be rewritten');
    }

    public function testAChangedFileNobodyMarkedIsLogged(): void
    {
        // Names the packages behind metadata_dump.file{written, requested:false}: content changed but
        // no path marked it, so only the transitional crawledAt clause caught it. That counter is the
        // gate on dropping the clause, and it is not at zero, so the log says which package to chase.
        $records = [];
        $this->rebuildDumperWithLogger(self::recordCapturingLogger($records));
        $this->primeBuildDir();

        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $this->store($package, $this->createVersion($package, '1.0.0'));
        $packageId = $package->getId();

        $this->requestDumpAt($packageId, '-1 minute');
        $this->dumper->dump([$packageId]);
        self::assertSame([], self::gapRecords($records), 'a marked package is the normal path and must stay quiet');

        // content changes with nothing marking it, which is exactly the gap being hunted
        $reloaded = self::getEM()->getRepository(Package::class)->find($packageId);
        self::assertInstanceOf(Package::class, $reloaded);
        $this->store($this->createVersion($reloaded, '2.0.0'));
        $this->dumper->dump([$packageId]);

        $gaps = self::gapRecords($records);
        self::assertCount(1, $gaps, 'only the tags file changed');
        self::assertSame(Logger::ERROR, $gaps[0]->level->value);
        self::assertSame('acme/package', $gaps[0]->context['package']);
        self::assertSame('p2/acme/package.json', $gaps[0]->context['file']);
        self::assertSame(['2.0.0.0'], $gaps[0]->context['changes']['added']);
        self::assertSame([], $gaps[0]->context['changes']['removed']);
        self::assertSame([], $gaps[0]->context['changes']['changed']);
    }

    public function testTheUnmarkedChangeLogNamesTheChangedField(): void
    {
        $records = [];
        $this->rebuildDumperWithLogger(self::recordCapturingLogger($records));
        $this->primeBuildDir();
        $packageId = $this->storeMarkedPackage('acme/package');
        $this->dumper->dump([$packageId]);

        self::getEM()->getConnection()->executeStatement('UPDATE package_version SET description = :d WHERE package_id = :id', ['d' => 'changed', 'id' => $packageId]);
        self::getEM()->clear();
        $this->dumper->dump([$packageId]);

        $gaps = self::gapRecords($records);
        self::assertCount(1, $gaps);
        self::assertSame(['description' => ['1.0.0.0']], $gaps[0]->context['changes']['changed']);
    }

    public function testTheUnmarkedChangeLogTellsAReorderingApart(): void
    {
        $records = [];
        $this->rebuildDumperWithLogger(self::recordCapturingLogger($records));
        $this->primeBuildDir();

        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $version = $this->createVersion($package, '1.0.0');
        $version->setLicense(['MIT', 'BSD-3-Clause']);
        $this->store($package, $version);
        $packageId = $package->getId();
        $this->requestDumpAt($packageId, '-1 minute');
        $this->dumper->dump([$packageId]);

        // same data, different order on disk: what a nondeterministic row order would look like
        $file = $this->buildDir.'/p2/acme/package.json';
        $metadata = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        $metadata['packages']['acme/package'][0]['license'] = ['BSD-3-Clause', 'MIT'];
        file_put_contents($file, json_encode($metadata, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

        self::getEM()->clear();
        $this->dumper->dump([$packageId]);

        $gaps = self::gapRecords($records);
        self::assertCount(1, $gaps);
        self::assertSame(['license (order)' => ['1.0.0.0']], $gaps[0]->context['changes']['changed']);
    }

    /**
     * @param list<LogRecord> $records
     *
     * @return list<LogRecord>
     */
    private static function gapRecords(array $records): array
    {
        return array_values(array_filter($records, static fn (LogRecord $record): bool => str_contains($record->message, 'nothing had marked')));
    }

    /**
     * Same as capturingLogger(), keeping the records whole so level and context can be asserted on.
     *
     * @param list<LogRecord> $captured
     */
    private static function recordCapturingLogger(array &$captured): Logger
    {
        $logger = new Logger('test', [new TestHandler()]);
        $logger->pushProcessor(static function (LogRecord $record) use (&$captured): LogRecord {
            $captured[] = $record;

            return $record;
        });

        return $logger;
    }

    /**
     * @param list<string> $logs
     *
     * @return list<string>
     */
    private static function gapWarnings(array $logs): array
    {
        return array_values(array_filter($logs, static fn (string $line): bool => str_contains($line, 'nothing had marked')));
    }

    public function testAForcedRunDoesNotLogEveryPackageAsUnmarked(): void
    {
        // --force dumps everything regardless of staleness, so every package in it looks unmarked.
        // Logging those would bury the handful of real gaps under a full-database run.
        $logs = [];
        $this->rebuildDumperWithLogger(self::capturingLogger($logs));
        $this->primeBuildDir();

        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $this->store($package, $this->createVersion($package, '1.0.0'));

        $this->dumper->dump([$package->getId()], force: true);

        self::assertSame([], self::gapWarnings($logs));
    }

    public function testForceDumpRewritesUnchangedContent(): void
    {
        // The deliberate escape hatch: forceDump() nulls dumpedAtV2, which the dumper reads as "write
        // this no matter what". That is what busts the CDN cache and re-runs the replica write for the
        // manual update button and for unfreeze, where nothing about the content has changed.
        $this->primeBuildDir();
        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $this->store($package, $this->createVersion($package, '1.0.0'));
        $packageId = $package->getId();
        $this->requestDumpAt($packageId, '-1 minute');

        $uploads = 0;
        $this->rebuildDumperWithUploadHook(static function () use (&$uploads): void {
            $uploads++;
        });

        $this->dumper->dump([$packageId]);
        self::assertSame(2, $uploads);

        $em = self::getEM();
        $package = $em->getRepository(Package::class)->find($packageId);
        self::assertNotNull($package);
        $package->forceDump();
        $em->flush();
        self::assertTrue($package->isDumpForced());
        $em->clear();

        $this->dumper->dump([$packageId]);
        self::assertSame(4, $uploads, 'a forced dump must re-write byte-identical content');
    }

    public function testAPackageLockedByAnUpdateIsLeftStaleUntilTheLockIsReleased(): void
    {
        // an update or deletion in progress would be read half-written, and a deletion's CDN cleanup
        // could be undone by an upload landing after it
        $uploads = 0;
        $this->rebuildDumperWithUploadHook(static function () use (&$uploads): void {
            $uploads++;
        });
        $this->primeBuildDir();
        $packageId = $this->storeMarkedPackage('acme/package');

        // GET_LOCK is re-entrant within a session, so the update has to hold it from another connection
        $updater = DriverManager::getConnection(self::getEM()->getConnection()->getParams());
        self::assertSame(1, (int) $updater->fetchOne('SELECT GET_LOCK(:name, 0)', ['name' => 'package_update_'.$packageId]));

        try {
            $result = $this->dumper->dump([$packageId]);
            self::assertSame(V2DumpOutcome::Complete, $result->outcome);
            self::assertSame([$packageId], $result->leftStale);
            self::assertSame(0, $uploads);
            self::assertFileDoesNotExist($this->buildDir.'/p2/acme/package.json');
        } finally {
            $updater->fetchOne('SELECT RELEASE_LOCK(:name)', ['name' => 'package_update_'.$packageId]);
            $updater->close();
        }

        $result = $this->dumper->dump([$packageId]);
        self::assertSame([], $result->leftStale);
        self::assertSame(2, $uploads);
        self::assertFileExists($this->buildDir.'/p2/acme/package.json');
    }

    public function testTheDumperReleasesTheUpdateLockOfEachPackageItDumped(): void
    {
        $this->primeBuildDir();
        $packageId = $this->storeMarkedPackage('acme/package');

        $this->dumper->dump([$packageId]);

        $updater = DriverManager::getConnection(self::getEM()->getConnection()->getParams());
        try {
            self::assertSame(1, (int) $updater->fetchOne('SELECT GET_LOCK(:name, 0)', ['name' => 'package_update_'.$packageId]), 'an update must be able to run once the package is dumped');
        } finally {
            $updater->close();
        }
    }

    public function testNumericallyEqualVersionsAreDumpedInTheSameOrderWhateverTheRowOrder(): void
    {
        $this->primeBuildDir();
        $released = new \DateTimeImmutable('2020-07-02 09:34:28');

        $ids = [];
        foreach (['acme/forward' => ['0.0.070', '0.0.70'], 'acme/backward' => ['0.0.70', '0.0.070']] as $name => $order) {
            $package = self::createPackage($name, 'https://example.com/'.$name);
            $versions = [];
            foreach ($order as $version) {
                $versions[] = $v = $this->createVersion($package, $version);
                $v->setReleasedAt($released);
            }
            $this->store($package, ...$versions);
            $ids[] = $package->getId();
        }

        $this->dumper->dump($ids, force: true);

        foreach (['acme/forward', 'acme/backward'] as $name) {
            $metadata = json_decode($this->localContent('p2/'.$name.'.json'), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame(['0.0.070', '0.0.70'], array_column($metadata['packages'][$name], 'version'), $name);
        }
    }

    /**
     * @return list<int>
     */
    private function stalePackageIds(): array
    {
        self::getEM()->clear();

        return array_keys(self::getEM()->getRepository(Package::class)->getStalePackagesForDumpingV2());
    }

    /**
     * Lets dump() run without --force, which is what exercises the real staleness bookkeeping — the
     * dumper otherwise refuses a first run against an empty build dir.
     */
    /**
     * Captures log records in a processor rather than reading them back off a TestHandler, because
     * V2Dumper::dump() calls logger->reset() between batches and that clears a handler's records.
     * Processors are not resettable, so what they have already collected survives.
     *
     * @param list<string> $captured
     */
    private static function capturingLogger(array &$captured): Logger
    {
        $logger = new Logger('test', [new TestHandler()]);
        $logger->pushProcessor(static function (LogRecord $record) use (&$captured): LogRecord {
            $captured[] = $record->message.' '.json_encode($record->context, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

            return $record;
        });

        return $logger;
    }

    private function rebuildDumperWithLogger(Logger $logger): void
    {
        $cdnClient = $this->createStub(CdnClient::class);
        $cdnClient->method('uploadMetadata')->willReturn(16062106090001);
        $cdnClient->method('purgeMetadataCache')->willReturn(true);

        $this->rebuildDumper($cdnClient, logger: $logger);
    }

    /**
     * @param \Closure(string): string      $fetch what the public CDN serves for a path
     * @param (\Closure(string): bool)|null $purge
     */
    private function verifyingCdnClient(\Closure $fetch, ?\Closure $purge = null): CdnClient
    {
        $this->primeBuildDir();
        // verification reads the local files through the web dir, which dump() only symlinks after verifying
        symlink($this->buildDir.'/p2', $this->webDir.'/p2');

        $cdnClient = $this->createStub(CdnClient::class);
        $cdnClient->method('isConfigured')->willReturn(true);
        $cdnClient->method('uploadMetadata')->willReturn(16062106090001);
        $cdnClient->method('fetchPublicMetadata')->willReturnCallback($fetch);
        $cdnClient->method('purgeMetadataCache')->willReturnCallback($purge ?? static fn (): bool => true);

        return $cdnClient;
    }

    private function localContent(string $relativePath): string
    {
        return (string) file_get_contents($this->buildDir.'/'.$relativePath);
    }

    private function storeMarkedPackage(string $name): int
    {
        $package = self::createPackage($name, 'https://example.com/'.$name);
        $this->store($package, $this->createVersion($package, '1.0.0'));
        $this->requestDumpAt($package->getId(), '-1 minute');

        return $package->getId();
    }

    /**
     * @param list<string> $logs
     *
     * @return list<string>
     */
    private static function logsStartingWith(array $logs, string $prefix): array
    {
        return array_values(array_filter($logs, static fn (string $log): bool => str_starts_with($log, $prefix)));
    }

    private function primeBuildDir(): void
    {
        mkdir($this->buildDir.'/p2', 0o777, true);
    }

    /**
     * Stamps a dump request at a controlled time. markForDump() would always land in the current
     * second, which is exactly the ambiguous case, so tests that are not about that boundary need
     * to place the request unambiguously in the past.
     */
    private function requestDumpAt(int $packageId, string $when): void
    {
        self::getEM()->getConnection()->executeStatement(
            'UPDATE package SET dumpRequestedAt = :when WHERE id = :id',
            ['when' => date('Y-m-d H:i:s', (int) strtotime($when)), 'id' => $packageId]
        );
        self::getEM()->clear();
    }

    /**
     * Rebuilds the dumper with a CDN client whose uploadMetadata() runs $hook, which lands between a
     * package's hydration and the end-of-run dumpedAtV2 write.
     */
    private function rebuildDumperWithUploadHook(\Closure $hook): void
    {
        $cdnClient = $this->createStub(CdnClient::class);
        $cdnClient->method('uploadMetadata')->willReturnCallback(static function () use ($hook): int {
            $hook();

            // a filemtime that does not end in '0000' so the Redis counter branch is skipped
            return 16062106090001;
        });
        $cdnClient->method('purgeMetadataCache')->willReturn(true);

        $this->rebuildDumper($cdnClient);
    }

    private function rebuildDumper(CdnClient $cdnClient, ?StatsDClient $statsd = null, ?Logger $logger = null): void
    {
        $this->dumper = new V2Dumper(
            static::getContainer()->get(ManagerRegistry::class),
            new Filesystem(),
            $this->createStub(RedisClient::class),
            $this->webDir,
            $this->buildDir,
            $statsd ?? $this->createStub(StatsDClient::class),
            $this->createStub(ProviderManager::class),
            $logger ?? new Logger('test'),
            $cdnClient,
            $this->createStub(ReplicaClient::class),
            static::getContainer()->get(UrlGeneratorInterface::class),
            new MockHttpClient(),
            static::getContainer()->get(FilterListDumperProvider::class),
            static::getContainer()->get(Locker::class),
        );
    }

    /**
     * @param list<array{string, array<string, string>}> $increments filled with each increment()'s metric and tags
     */
    private function recordingStatsd(array &$increments): StatsDClient
    {
        $statsd = $this->createStub(StatsDClient::class);
        $statsd->method('increment')->willReturnCallback(static function (string $metric, int $delta = 1, float $rate = 1.0, array $tags = []) use (&$increments, $statsd): StatsDClient {
            $increments[] = [$metric, $tags];

            return $statsd;
        });

        return $statsd;
    }

    /**
     * @param list<array{string, array<string, string>}> $increments
     *
     * @return list<array<string, string>>
     */
    private static function tagsOf(array $increments, string $metric): array
    {
        return array_values(array_map(static fn (array $call): array => $call[1], array_filter($increments, static fn (array $call): bool => $call[0] === $metric)));
    }

    private function createVersion(Package $package, string $version): Version
    {
        $versionParser = new VersionParser();
        $isDev = VersionParser::parseStability($version) === 'dev';
        $v = new Version();
        $v->setName($package->getName());
        $v->setVersion($version);
        $v->setNormalizedVersion($isDev ? $version : $versionParser->normalize($version));
        $v->setLicense(['MIT']);
        $v->setAutoload([]);
        $v->setDevelopment($isDev);
        $v->setPackage($package);
        $package->getVersions()->add($v);
        $v->setReleasedAt(new \DateTimeImmutable());
        // distinct createdAt/updatedAt so published-time can prove stable uses createdAt, dev uses updatedAt
        $v->setCreatedAt(new \DateTimeImmutable('2024-01-01 12:00:00'));
        $v->setUpdatedAt(new \DateTimeImmutable('2024-02-02 12:00:00'));

        return $v;
    }
}
