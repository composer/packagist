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
use App\Entity\Version;
use App\Entity\VersionListItem;
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

    public function testSoftDeleteOfAlreadySoftDeletedVersionRestampsAndSkipsRecrawl(): void
    {
        $em = self::getEM();
        $version = $this->seedStableVersion('vendor/sd-rehide', '2.0.0', '2.0.0.0');
        $packageId = $version->getPackage()->getId();

        $this->versionRepository->softDelete($version, VersionDeletionReason::AutoDeletedMissing, null, null, null);
        // Backdate the first removal so the restamp below is unambiguous rather than same-second.
        $originalSoftDeletedAt = new \DateTimeImmutable('2024-01-02 03:04:05');
        $version->setSoftDeletedAt($originalSoftDeletedAt);
        $em->flush();

        $jobRepo = $em->getRepository(Job::class);
        self::assertCount(1, $jobRepo->findBy(['type' => 'package:updates', 'packageId' => $packageId]));

        // An admin hiding the already soft-deleted version rewrites the reason and the removal time.
        $this->versionRepository->softDelete($version, VersionDeletionReason::Hidden, 'spam', 'ticket #7', null);
        $em->flush();
        $em->clear();

        $reloaded = $this->versionRepository->find($version->getId());
        self::assertNotNull($reloaded);
        self::assertSame(VersionDeletionReason::Hidden, $reloaded->getDeletionReason());
        self::assertSame('spam', $reloaded->getDeletionReasonText());
        self::assertSame('ticket #7', $reloaded->getInternalDeletionReasonText());
        self::assertNotNull($reloaded->getSoftDeletedAt());
        self::assertGreaterThan(
            $originalSoftDeletedAt,
            $reloaded->getSoftDeletedAt(),
            'A reason change restamps the removal time; the audit log keeps the detailed timeline'
        );

        // Nothing to recompute: dumps filter on isSoftDeleted() alone, so no extra crawl is queued.
        self::assertCount(1, $em->getRepository(Job::class)->findBy(['type' => 'package:updates', 'packageId' => $packageId]));

        $audits = $em->getRepository(AuditRecord::class)->findBy([
            'type' => AuditRecordType::VersionSoftDeleted->value,
            'packageId' => $packageId,
        ]);
        self::assertCount(2, $audits, 'The reason change should be audited as its own record');
        self::assertSame(
            [VersionDeletionReason::AutoDeletedMissing->value, VersionDeletionReason::Hidden->value],
            array_map(static fn (AuditRecord $a): string => $a->attributes['reason'], $audits)
        );
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

    public function testGetVersionListForPackageMatchesTheFullEntities(): void
    {
        $em = self::getEM();
        $package = self::createPackage('vendor/listpkg', 'https://github.com/vendor/listpkg');

        $stable = $this->buildVersion($package, '1.0.0', '1.0.0.0');

        $dev = $this->buildVersion($package, 'dev-main', 'dev-main');
        $dev->setDevelopment(true);
        $dev->setIsDefaultBranch(true);
        $dev->setExtra(['branch-alias' => ['dev-main' => '2.x-dev']]);
        $dev->setReleasedAt(new \DateTimeImmutable('2026-01-02 03:04:05'));

        $deleted = $this->buildVersion($package, '0.9.0', '0.9.0.0');
        $deleted->setSoftDeletedAt(new \DateTimeImmutable('2026-02-03 04:05:06'));
        $deleted->setDeletionReason(VersionDeletionReason::DeletedByAdmin);
        $deleted->setDeletionReasonText('bogus release');
        $deleted->setInternalDeletionReasonText('reported by upstream');
        $deleted->setLastBlockedReference('abc123');

        $this->store($package, $stable, $dev, $deleted);
        $em->clear();

        $package = $em->getRepository(Package::class)->getPackageByName('vendor/listpkg');
        $items = $this->versionRepository->getVersionListForPackage($package);
        self::assertCount(3, $items);

        /** @var array<int, VersionListItem> $byId */
        $byId = [];
        foreach ($items as $item) {
            $byId[$item->getId()] = $item;
        }

        // every list item must be indistinguishable from the entity it stands in for, since the
        // template, the sort comparator and the deletion-title filter run on both
        foreach ($this->versionRepository->findBy(['package' => $package]) as $entity) {
            $item = $byId[$entity->getId()];
            self::assertSame($entity->getVersion(), $item->getVersion());
            self::assertSame($entity->getNormalizedVersion(), $item->getNormalizedVersion());
            self::assertSame($entity->getMajorVersion(), $item->getMajorVersion());
            self::assertSame($entity->isDevelopment(), $item->isDevelopment());
            self::assertSame($entity->isDefaultBranch(), $item->isDefaultBranch());
            self::assertSame($entity->isSoftDeleted(), $item->isSoftDeleted());
            self::assertSame($entity->getDeletionReason(), $item->getDeletionReason());
            self::assertSame($entity->getDeletionReasonText(), $item->getDeletionReasonText());
            self::assertSame($entity->getInternalDeletionReasonText(), $item->getInternalDeletionReasonText());
            self::assertSame($entity->getLastBlockedReference(), $item->getLastBlockedReference());
            self::assertSame($entity->getExtra(), $item->getExtra());
            self::assertSame($entity->hasVersionAlias(), $item->hasVersionAlias());
            self::assertSame($entity->getVersionAlias(), $item->getVersionAlias());
            self::assertSame($entity->getDeletionTitle(), $item->getDeletionTitle());
            self::assertSame($entity->getDeletionTitle(true), $item->getDeletionTitle(true));
            self::assertEquals($entity->getReleasedAt(), $item->getReleasedAt());
            self::assertEquals($entity->getSoftDeletedAt(), $item->getSoftDeletedAt());
            self::assertSame($package, $item->getPackage());
        }

        self::assertSame('2.x-dev', $byId[$dev->getId()]->getVersionAlias());
        self::assertSame(
            'Removed by admin on 2026-02-03 04:05:06 UTC: bogus release (Internal reason: reported by upstream)',
            $byId[$deleted->getId()]->getDeletionTitle(true)
        );
        self::assertSame('Removed by admin on 2026-02-03 04:05:06 UTC: bogus release', $byId[$deleted->getId()]->getDeletionTitle());
        self::assertNull($byId[$stable->getId()]->getDeletionTitle());
    }

    public function testGetVersionListForPackageSortsLikeTheFullEntities(): void
    {
        $em = self::getEM();
        $package = self::createPackage('vendor/sortpkg', 'https://github.com/vendor/sortpkg');

        $versions = [];
        foreach ([['1.0.0', '1.0.0.0'], ['1.10.0', '1.10.0.0'], ['1.2.0', '1.2.0.0'], ['2.0.0', '2.0.0.0']] as [$v, $normalized]) {
            $versions[] = $this->buildVersion($package, $v, $normalized);
        }
        $dev = $this->buildVersion($package, 'dev-main', 'dev-main');
        $dev->setDevelopment(true);
        $dev->setIsDefaultBranch(true);
        $versions[] = $dev;

        $this->store($package, ...$versions);
        $em->clear();

        $package = $em->getRepository(Package::class)->getPackageByName('vendor/sortpkg');

        $entities = $this->versionRepository->findBy(['package' => $package]);
        usort($entities, Package::class.'::sortVersions');

        $items = $this->versionRepository->getVersionListForPackage($package);
        usort($items, Package::class.'::sortVersions');

        self::assertSame(
            array_map(static fn (Version $v): string => $v->getVersion(), $entities),
            array_map(static fn (VersionListItem $v): string => $v->getVersion(), $items)
        );
        self::assertSame(['dev-main', '2.0.0', '1.10.0', '1.2.0', '1.0.0'], array_map(static fn (VersionListItem $v): string => $v->getVersion(), $items));
    }

    private function buildVersion(Package $package, string $version, string $normalized): Version
    {
        $v = new Version();
        $v->setPackage($package);
        $v->setName($package->getName());
        $v->setVersion($version);
        $v->setNormalizedVersion($normalized);
        $v->setDevelopment(false);
        $v->setLicense([]);
        $v->setAutoload([]);
        $package->getVersions()->add($v);

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
