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

namespace App\Tests\Entity;

use App\Audit\AuditRecordType;
use App\Audit\VersionDeletionReason;
use App\Entity\AuditRecord;
use App\Entity\Job;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\Version;
use App\Entity\VersionRepository;
use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\TestWith;

class VersionRepositoryTest extends IntegrationTestCase
{
    private VersionRepository $versionRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->versionRepository = self::getEM()->getRepository(Version::class);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testRemoveVersionMarksForRemovalWithAuditRecord(bool $createAuditRecord): void
    {
        $em = self::getEM();

        $package = self::createPackage('vendor/package', 'https://github.com/vendor/package');

        $version = new Version();
        $version->setPackage($package);
        $version->setName($package->getName());
        // Dev versions are the ones legitimately hard-deleted (prune housekeeping, ClearVersions);
        // stable versions are immutable and remove() refuses them (see testRemoveRefusesStableVersion).
        $version->setVersion('dev-main');
        $version->setNormalizedVersion('dev-main');
        $version->setDevelopment(true);
        $version->setLicense([]);
        $version->setAutoload([]);
        $package->getVersions()->add($version);

        $this->store($package, $version);

        $versionId = $version->getId();
        $this->versionRepository->remove($version, $createAuditRecord);

        $em->flush();
        $em->clear();

        $this->assertNull($this->versionRepository->find($versionId), 'Version was not deleted');

        $auditRecord = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::VersionDeleted->value,
            'packageId' => $package->getId(),
            'actorId' => null,
        ]);

        if ($createAuditRecord) {
            $this->assertNotNull($auditRecord, 'No audit record for version deletion created');
        } else {
            $this->assertNull($auditRecord, 'Audit record for version deleted created');
        }
    }

    public function testSoftDeleteMarksReasonAndWritesAudit(): void
    {
        $em = self::getEM();
        $version = $this->seedStableVersion('vendor/sd', '2.0.0', '2.0.0.0');

        $this->versionRepository->softDelete($version, VersionDeletionReason::DeletedByMaintainer, null, null, null);
        $em->flush();
        $em->clear();

        $reloaded = $this->versionRepository->find($version->getId());
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getSoftDeletedAt());
        self::assertSame(VersionDeletionReason::DeletedByMaintainer, $reloaded->getDeletionReason());
        self::assertNull($reloaded->getDeletionReasonText());
        self::assertNull($reloaded->getInternalDeletionReasonText());

        $audit = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::VersionSoftDeleted->value,
            'packageId' => $reloaded->getPackage()->getId(),
        ]);
        self::assertNotNull($audit, 'softDelete() should write a VersionSoftDeleted audit row');
        self::assertSame(VersionDeletionReason::DeletedByMaintainer->value, $audit->attributes['reason']);
    }

    public function testSoftDeletePersistsAdminReasonText(): void
    {
        $em = self::getEM();
        $version = $this->seedStableVersion('vendor/sd-admin', '2.0.0', '2.0.0.0');

        $this->versionRepository->softDelete($version, VersionDeletionReason::DeletedByAdmin, 'legal takedown', 'reporter john@example.com, ticket #42', null);
        $em->flush();
        $em->clear();

        $reloaded = $this->versionRepository->find($version->getId());
        self::assertNotNull($reloaded);
        self::assertSame(VersionDeletionReason::DeletedByAdmin, $reloaded->getDeletionReason());
        self::assertSame('legal takedown', $reloaded->getDeletionReasonText());
        self::assertSame('reporter john@example.com, ticket #42', $reloaded->getInternalDeletionReasonText());

        $audit = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::VersionSoftDeleted->value,
            'packageId' => $reloaded->getPackage()->getId(),
        ]);
        self::assertNotNull($audit);
        self::assertSame('legal takedown', $audit->attributes['reasonText']);
        self::assertSame('reporter john@example.com, ticket #42', $audit->attributes['internalReasonText']);
    }

    public function testRecoverClearsAllSoftDeleteState(): void
    {
        $em = self::getEM();
        $version = $this->seedStableVersion('vendor/recover', '2.0.0', '2.0.0.0');

        $this->versionRepository->softDelete($version, VersionDeletionReason::DeletedByAdmin, 'public', 'internal note', null);
        $em->flush();

        $this->versionRepository->recover($version, null);
        $em->flush();
        $em->clear();

        $reloaded = $this->versionRepository->find($version->getId());
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getSoftDeletedAt());
        self::assertNull($reloaded->getDeletionReason());
        self::assertNull($reloaded->getDeletionReasonText());
        self::assertNull($reloaded->getInternalDeletionReasonText());

        $audit = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::VersionRecovered->value,
            'packageId' => $reloaded->getPackage()->getId(),
        ]);
        self::assertNotNull($audit, 'recover() should write a VersionRecovered audit row');
        self::assertSame(VersionDeletionReason::DeletedByAdmin->value, $audit->attributes['previousReason']);
    }

    public function testGetVersionMetadataForUpdateIncludesNewProjection(): void
    {
        $em = self::getEM();
        $version = $this->seedStableVersion('vendor/projection', '2.0.0', '2.0.0.0');
        $version->setLastBlockedReference('aabbccdd');
        $em->persist($version);
        $em->flush();

        $meta = $this->versionRepository->getVersionMetadataForUpdate($version->getPackage());
        self::assertArrayHasKey('2.0.0.0', $meta);
        self::assertArrayHasKey('dist', $meta['2.0.0.0']);
        self::assertArrayHasKey('deletionReason', $meta['2.0.0.0']);
        self::assertArrayHasKey('lastBlockedReference', $meta['2.0.0.0']);
        self::assertSame('aabbccdd', $meta['2.0.0.0']['lastBlockedReference']);
    }

    public function testRemoveRefusesStableVersion(): void
    {
        $version = $this->seedStableVersion('vendor/immutable', '2.0.0', '2.0.0.0');
        $versionId = $version->getId();

        try {
            $this->versionRepository->remove($version);
            self::fail('Expected a LogicException when hard-deleting a stable version');
        } catch (\LogicException $e) {
            self::assertStringContainsString('immutable', $e->getMessage());
        }

        self::getEM()->flush();
        self::getEM()->clear();
        self::assertNotNull($this->versionRepository->find($versionId), 'stable version must survive a refused hard-delete');
    }

    public function testRemoveAllowsStableVersionWithOptOut(): void
    {
        $version = $this->seedStableVersion('vendor/wholepkg', '2.0.0', '2.0.0.0');
        $versionId = $version->getId();

        // allowStable is the whole-package-deletion escape hatch (PackageManager::deletePackage,
        // CleanSpamPackagesCommand) where the entire package and all its slots are removed.
        $this->versionRepository->remove($version, allowStable: true);
        self::getEM()->flush();
        self::getEM()->clear();

        self::assertNull($this->versionRepository->find($versionId), 'allowStable must permit hard-deleting a stable version');
    }

    public function testRemoveMarksThePackageForRedump(): void
    {
        $version = $this->seedDevVersion('vendor/removed', 'dev-main');
        $package = $version->getPackage();
        $this->markPackageAsDumped($package);

        $this->versionRepository->remove($version);
        self::getEM()->flush();

        self::assertTrue($package->isDumpRequested(), 'removing a live version drops it from the metadata, so it must mark for re-dump');
    }

    public function testRemoveDoesNotMarkForRedumpWhenTheVersionWasAlreadySoftDeleted(): void
    {
        // The dominant caller is the Updater prune loop, which hard-purges dev rows a day after they
        // were soft-deleted. Those were already excluded from the dumped metadata, so the purge cannot
        // change a byte and must not trigger a full re-dump — this is routine branch churn.
        $version = $this->seedDevVersion('vendor/already-gone', 'dev-stale');
        $version->setSoftDeletedAt(new \DateTimeImmutable('-2 days'));
        $version->setDeletionReason(VersionDeletionReason::AutoDeletedMissing);
        $package = $version->getPackage();
        $this->store($package, $version);
        $this->markPackageAsDumped($package);

        $this->versionRepository->remove($version);
        self::getEM()->flush();

        self::assertNull($package->getDumpRequestedAt(), 'purging an already-soft-deleted row changes no dumped bytes');
        self::assertFalse($package->isDumpRequested());
    }

    public function testSoftDeleteMarksForRedumpDirectlyRatherThanRelyingOnTheJob(): void
    {
        // Pulling a version is the security path: it must not depend on a crawl succeeding, nor on the
        // job's force_dump surviving Scheduler dedup.
        $version = $this->seedStableVersion('vendor/sd-dump', '2.0.0', '2.0.0.0');
        $package = $version->getPackage();
        $this->markPackageAsDumped($package);

        $this->versionRepository->softDelete($version, VersionDeletionReason::DeletedByAdmin, null, null, null);
        self::getEM()->flush();

        self::assertTrue($package->isDumpRequested(), 'a pulled version must be marked for re-dump immediately');

        $job = self::getEM()->getRepository(Job::class)->findOneBy(['type' => 'package:updates', 'packageId' => $package->getId()]);
        self::assertNotNull($job, 'a crawl is still scheduled so dependents/suggesters get recomputed');
        self::assertTrue($job->getPayload()['force_dump'] ?? false);
        self::assertSame('version_soft_delete', $job->getPayload()['source'] ?? null, 'the soft-delete path must not be filed as a recover in update-origin telemetry');
    }

    public function testSoftDeleteOnAFrozenPackageMarksForRedump(): void
    {
        // frozen packages are skipped by the Updater entirely, so no job is scheduled for them
        $version = $this->seedStableVersion('vendor/sd-frozen', '2.0.0', '2.0.0.0');
        $package = $version->getPackage();
        $package->freeze(PackageFreezeReason::Spam);
        $this->store($package);
        $this->markPackageAsDumped($package);

        $this->versionRepository->softDelete($version, VersionDeletionReason::DeletedByAdmin, null, null, null);
        self::getEM()->flush();

        self::assertTrue($package->isDumpRequested());
        self::assertNull(
            self::getEM()->getRepository(Job::class)->findOneBy(['type' => 'package:updates', 'packageId' => $package->getId()]),
            'no crawl is scheduled for a frozen package'
        );
    }

    public function testRecoverMarksForRedumpDirectly(): void
    {
        $version = $this->seedStableVersion('vendor/recover-dump', '2.0.0', '2.0.0.0');
        $package = $version->getPackage();

        $this->versionRepository->softDelete($version, VersionDeletionReason::DeletedByAdmin, null, null, null);
        self::getEM()->flush();
        $this->markPackageAsDumped($package);

        $this->versionRepository->recover($version, null);
        self::getEM()->flush();

        self::assertTrue($package->isDumpRequested(), 'a recovered version rejoins the metadata, so it must mark for re-dump');
    }

    /**
     * Puts the package in a "freshly dumped, nothing pending" state, i.e. isDumpRequested() === false.
     */
    private function markPackageAsDumped(Package $package): void
    {
        $package->setDumpedAtV2(new \DateTimeImmutable());
        $this->store($package);
        self::assertFalse($package->isDumpRequested());
    }

    private function seedDevVersion(string $packageName, string $version): Version
    {
        $package = self::createPackage($packageName, 'https://github.com/'.$packageName);

        $v = new Version();
        $v->setPackage($package);
        $v->setName($package->getName());
        $v->setVersion($version);
        $v->setNormalizedVersion($version);
        $v->setDevelopment(true);
        $v->setLicense([]);
        $v->setAutoload([]);
        $package->getVersions()->add($v);

        $this->store($package, $v);

        return $v;
    }

    private function seedStableVersion(string $packageName, string $version, string $normalized): Version
    {
        $package = self::createPackage($packageName, 'https://github.com/'.$packageName);

        $v = new Version();
        $v->setPackage($package);
        $v->setName($package->getName());
        $v->setVersion($version);
        $v->setNormalizedVersion($normalized);
        $v->setDevelopment(false);
        $v->setLicense([]);
        $v->setAutoload([]);
        $package->getVersions()->add($v);

        $this->store($package, $v);

        return $v;
    }
}
