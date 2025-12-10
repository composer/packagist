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
}
