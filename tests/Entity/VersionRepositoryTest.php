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
        $version->setVersion('1.0.0');
        $version->setNormalizedVersion('1.0.0.0');
        $version->setDevelopment(false);
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

        $this->versionRepository->softDelete($version, VersionDeletionReason::DeletedByMaintainer, null, null);
        $em->flush();
        $em->clear();

        $reloaded = $this->versionRepository->find($version->getId());
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getSoftDeletedAt());
        self::assertSame(VersionDeletionReason::DeletedByMaintainer, $reloaded->getDeletionReason());
        self::assertNull($reloaded->getDeletionReasonText());

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

        $this->versionRepository->softDelete($version, VersionDeletionReason::DeletedByAdmin, 'legal takedown', null);
        $em->flush();
        $em->clear();

        $reloaded = $this->versionRepository->find($version->getId());
        self::assertNotNull($reloaded);
        self::assertSame(VersionDeletionReason::DeletedByAdmin, $reloaded->getDeletionReason());
        self::assertSame('legal takedown', $reloaded->getDeletionReasonText());

        $audit = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::VersionSoftDeleted->value,
            'packageId' => $reloaded->getPackage()->getId(),
        ]);
        self::assertNotNull($audit);
        self::assertSame('legal takedown', $audit->attributes['reasonText']);
    }

    public function testRecoverClearsAllSoftDeleteState(): void
    {
        $em = self::getEM();
        $version = $this->seedStableVersion('vendor/recover', '2.0.0', '2.0.0.0');

        $this->versionRepository->softDelete($version, VersionDeletionReason::DeletedByMaintainer, null, null);
        $em->flush();

        $this->versionRepository->recover($version, null);
        $em->flush();
        $em->clear();

        $reloaded = $this->versionRepository->find($version->getId());
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getSoftDeletedAt());
        self::assertNull($reloaded->getDeletionReason());
        self::assertNull($reloaded->getDeletionReasonText());

        $audit = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::VersionRecovered->value,
            'packageId' => $reloaded->getPackage()->getId(),
        ]);
        self::assertNotNull($audit, 'recover() should write a VersionRecovered audit row');
        self::assertSame(VersionDeletionReason::DeletedByMaintainer->value, $audit->attributes['previousReason']);
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
