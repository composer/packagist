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

namespace App\Tests\Audit\Display;

use App\Audit\AuditRecordType;
use App\Audit\Display\ActorDisplay;
use App\Audit\Display\AuditLogDisplayFactory;
use App\Audit\Display\CanonicalUrlChangedDisplay;
use App\Audit\Display\PackageAbandonedDisplay;
use App\Audit\Display\PackageCreatedDisplay;
use App\Audit\Display\PackageDeletedDisplay;
use App\Audit\Display\PackageUnabandonedDisplay;
use App\Audit\Display\VersionDeletedDisplay;
use App\Audit\Display\VersionReferenceChangedDisplay;
use App\Entity\AuditRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuditLogDisplayFactoryTest extends TestCase
{
    private AuditLogDisplayFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new AuditLogDisplayFactory();
    }

    public function testBuildPackageCreatedWithUserActor(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditRecordType::PackageCreated,
            [
                'name' => 'vendor/package',
                'repository' => 'https://github.com/vendor/package',
                'actor' => ['id' => 123, 'username' => 'testuser'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageCreatedDisplay::class, $display);
        self::assertSame('vendor/package', $display->packageName);
        self::assertSame('https://github.com/vendor/package', $display->repository);
        self::assertSame(123, $display->actor->id);
        self::assertSame('testuser', $display->actor->username);
        self::assertSame(AuditRecordType::PackageCreated, $display->getType());
        self::assertSame('audit_log/display/package_created.html.twig', $display->getTemplateName());
        self::assertSame('audit_log.type.package_created', $display->getTypeTranslationKey());
    }

    public function testBuildPackageCreatedWithSystemActor(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditRecordType::PackageCreated,
            [
                'name' => 'vendor/package',
                'repository' => 'https://github.com/vendor/package',
                'actor' => 'automation',
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageCreatedDisplay::class, $display);
        self::assertNull($display->actor->id);
        self::assertSame('automation', $display->actor->username);
    }

    public function testBuildPackageDeleted(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditRecordType::PackageDeleted,
            [
                'name' => 'vendor/package',
                'repository' => 'https://github.com/vendor/package',
                'actor' => ['id' => 456, 'username' => 'admin'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageDeletedDisplay::class, $display);
        self::assertSame('vendor/package', $display->packageName);
        self::assertSame('https://github.com/vendor/package', $display->repository);
        self::assertSame(456, $display->actor->id);
        self::assertSame('admin', $display->actor->username);
        self::assertSame(AuditRecordType::PackageDeleted, $display->getType());
        self::assertSame('audit_log/display/package_deleted.html.twig', $display->getTemplateName());
    }

    public function testBuildCanonicalUrlChanged(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditRecordType::CanonicalUrlChanged,
            [
                'name' => 'vendor/package',
                'repository_from' => 'https://github.com/vendor/old-package',
                'repository_to' => 'https://github.com/vendor/new-package',
                'actor' => ['id' => 789, 'username' => 'maintainer'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(CanonicalUrlChangedDisplay::class, $display);
        self::assertSame('vendor/package', $display->packageName);
        self::assertSame('https://github.com/vendor/old-package', $display->repositoryFrom);
        self::assertSame('https://github.com/vendor/new-package', $display->repositoryTo);
        self::assertSame(789, $display->actor->id);
        self::assertSame('maintainer', $display->actor->username);
        self::assertSame(AuditRecordType::CanonicalUrlChanged, $display->getType());
        self::assertSame('audit_log/display/canonical_url_changed.html.twig', $display->getTemplateName());
    }

    public function testBuildVersionDeleted(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditRecordType::VersionDeleted,
            [
                'name' => 'vendor/package',
                'version' => '1.0.0',
                'actor' => ['id' => 111, 'username' => 'moderator'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(VersionDeletedDisplay::class, $display);
        self::assertSame('vendor/package', $display->packageName);
        self::assertSame('1.0.0', $display->version);
        self::assertSame(111, $display->actor->id);
        self::assertSame('moderator', $display->actor->username);
        self::assertSame(AuditRecordType::VersionDeleted, $display->getType());
        self::assertSame('audit_log/display/version_deleted.html.twig', $display->getTemplateName());
    }

    public function testBuildVersionReferenceChangedWithSourceOnly(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditRecordType::VersionReferenceChanged,
            [
                'name' => 'vendor/package',
                'version' => '2.0.0',
                'source_from' => 'abc123',
                'source_to' => 'def456',
                'actor' => ['id' => 222, 'username' => 'releaser'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(VersionReferenceChangedDisplay::class, $display);
        self::assertSame('vendor/package', $display->packageName);
        self::assertSame('2.0.0', $display->version);
        self::assertSame('abc123', $display->sourceFrom);
        self::assertSame('def456', $display->sourceTo);
        self::assertNull($display->distFrom);
        self::assertNull($display->distTo);
        self::assertSame(222, $display->getActor()->id);
        self::assertSame('releaser', $display->getActor()->username);
        self::assertSame(AuditRecordType::VersionReferenceChanged, $display->getType());
        self::assertSame('audit_log/display/version_reference_changed.html.twig', $display->getTemplateName());
    }

    public function testBuildVersionReferenceChangedWithDistOnly(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditRecordType::VersionReferenceChanged,
            [
                'name' => 'vendor/package',
                'version' => '2.0.0',
                'dist_from' => 'xyz789',
                'dist_to' => 'uvw012',
                'actor' => ['id' => 333, 'username' => 'updater'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(VersionReferenceChangedDisplay::class, $display);
        self::assertNull($display->sourceFrom);
        self::assertNull($display->sourceTo);
        self::assertSame('xyz789', $display->distFrom);
        self::assertSame('uvw012', $display->distTo);
        self::assertSame(333, $display->getActor()->id);
        self::assertSame('updater', $display->getActor()->username);
    }

    public function testBuildVersionReferenceChangedWithBothSourceAndDist(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditRecordType::VersionReferenceChanged,
            [
                'name' => 'vendor/package',
                'version' => '3.0.0',
                'source_from' => 'abc123',
                'source_to' => 'def456',
                'dist_from' => 'xyz789',
                'dist_to' => 'uvw012',
                'actor' => ['id' => 444, 'username' => 'publisher'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(VersionReferenceChangedDisplay::class, $display);
        self::assertSame('abc123', $display->sourceFrom);
        self::assertSame('def456', $display->sourceTo);
        self::assertSame('xyz789', $display->distFrom);
        self::assertSame('uvw012', $display->distTo);
        self::assertSame(444, $display->getActor()->id);
        self::assertSame('publisher', $display->getActor()->username);
    }

    public function testBuildVersionReferenceChangedWithoutActor(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditRecordType::VersionReferenceChanged,
            [
                'name' => 'vendor/package',
                'version' => '4.0.0',
                'source_from' => 'abc123',
                'source_to' => 'def456',
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(VersionReferenceChangedDisplay::class, $display);
        self::assertSame('vendor/package', $display->packageName);
        self::assertSame('4.0.0', $display->version);
        self::assertNull($display->getActor()->id);
        self::assertSame('unknown', $display->getActor()->username);
    }

    public function testBuildPackageAbandonedWithReplacement(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditRecordType::PackageAbandoned,
            [
                'name' => 'vendor/old-package',
                'repository' => 'https://github.com/vendor/old-package',
                'replacement_package' => 'vendor/new-package',
                'reason' => 'manual',
                'actor' => ['id' => 123, 'username' => 'maintainer'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageAbandonedDisplay::class, $display);
        self::assertSame('vendor/old-package', $display->packageName);
        self::assertSame('https://github.com/vendor/old-package', $display->repository);
        self::assertSame('vendor/new-package', $display->replacementPackage);
        self::assertSame('manual', $display->reason);
        self::assertSame(123, $display->actor->id);
        self::assertSame('maintainer', $display->actor->username);
        self::assertSame(AuditRecordType::PackageAbandoned, $display->getType());
        self::assertSame('audit_log/display/package_abandoned.html.twig', $display->getTemplateName());
    }

    public function testBuildPackageAbandonedWithoutReplacement(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditRecordType::PackageAbandoned,
            [
                'name' => 'vendor/abandoned-package',
                'repository' => 'https://github.com/vendor/abandoned-package',
                'replacement_package' => null,
                'reason' => 'repository_archived',
                'actor' => 'automation',
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageAbandonedDisplay::class, $display);
        self::assertSame('vendor/abandoned-package', $display->packageName);
        self::assertSame('https://github.com/vendor/abandoned-package', $display->repository);
        self::assertNull($display->replacementPackage);
        self::assertSame('repository_archived', $display->reason);
        self::assertNull($display->actor->id);
        self::assertSame('automation', $display->actor->username);
    }

    public function testBuildPackageUnabandoned(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditRecordType::PackageUnabandoned,
            [
                'name' => 'vendor/restored-package',
                'repository' => 'https://github.com/vendor/restored-package',
                'previous_replacement_package' => 'vendor/replacement',
                'actor' => ['id' => 234, 'username' => 'maintainer'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageUnabandonedDisplay::class, $display);
        self::assertSame('vendor/restored-package', $display->packageName);
        self::assertSame('https://github.com/vendor/restored-package', $display->repository);
        self::assertSame('vendor/replacement', $display->previousReplacementPackage);
        self::assertSame(234, $display->actor->id);
        self::assertSame('maintainer', $display->actor->username);
        self::assertSame(AuditRecordType::PackageUnabandoned, $display->getType());
        self::assertSame('audit_log/display/package_unabandoned.html.twig', $display->getTemplateName());
    }

    public function testBuildPackageUnabandonedWithoutPreviousReplacement(): void
    {
        $auditRecord = $this->createAuditRecord(
            AuditRecordType::PackageUnabandoned,
            [
                'name' => 'vendor/restored-package',
                'repository' => 'https://github.com/vendor/restored-package',
                'previous_replacement_package' => null,
                'actor' => ['id' => 777, 'username' => 'maintainer'],
            ]
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertInstanceOf(PackageUnabandonedDisplay::class, $display);
        self::assertNull($display->previousReplacementPackage);
        self::assertSame(777, $display->actor->id);
        self::assertSame('maintainer', $display->actor->username);
    }

    public function testBuildMultipleRecords(): void
    {
        $records = [
            $this->createAuditRecord(
                AuditRecordType::PackageCreated,
                [
                    'name' => 'vendor/package1',
                    'repository' => 'https://github.com/vendor/package1',
                    'actor' => ['id' => 1, 'username' => 'user1'],
                ]
            ),
            $this->createAuditRecord(
                AuditRecordType::PackageDeleted,
                [
                    'name' => 'vendor/package2',
                    'repository' => 'https://github.com/vendor/package2',
                    'actor' => ['id' => 2, 'username' => 'user2'],
                ]
            ),
            $this->createAuditRecord(
                AuditRecordType::VersionDeleted,
                [
                    'name' => 'vendor/package3',
                    'version' => '1.0.0',
                    'actor' => ['id' => 3, 'username' => 'user3'],
                ]
            ),
        ];

        $displays = $this->factory->build($records);

        self::assertCount(3, $displays);
        self::assertInstanceOf(PackageCreatedDisplay::class, $displays[0]);
        self::assertInstanceOf(PackageDeletedDisplay::class, $displays[1]);
        self::assertInstanceOf(VersionDeletedDisplay::class, $displays[2]);
        self::assertSame('vendor/package1', $displays[0]->packageName);
        self::assertSame('vendor/package2', $displays[1]->packageName);
        self::assertSame('vendor/package3', $displays[2]->packageName);
    }

    public function testDateTimeIsPreserved(): void
    {
        $datetime = new \DateTimeImmutable('2024-01-15 10:30:00');
        $auditRecord = $this->createAuditRecord(
            AuditRecordType::PackageCreated,
            [
                'name' => 'vendor/package',
                'repository' => 'https://github.com/vendor/package',
                'actor' => ['id' => 1, 'username' => 'user'],
            ],
            $datetime
        );

        $display = $this->factory->buildSingle($auditRecord);

        self::assertSame($datetime, $display->getDateTime());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createAuditRecord(
        AuditRecordType $type,
        array $attributes,
        ?\DateTimeImmutable $datetime = null
    ): AuditRecord {
        $datetime = $datetime ?? new \DateTimeImmutable();

        $reflection = new \ReflectionClass(AuditRecord::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $datetimeProperty = $reflection->getProperty('datetime');
        $datetimeProperty->setValue($instance, $datetime);

        $typeProperty = $reflection->getProperty('type');
        $typeProperty->setValue($instance, $type);

        $attributesProperty = $reflection->getProperty('attributes');
        $attributesProperty->setValue($instance, $attributes);

        return $instance;
    }
}
