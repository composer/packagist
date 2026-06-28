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

namespace App\Tests\Audit;

use App\Audit\AuditRecordType;
use App\Entity\AuditRecord;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

class OrganizationAuditRecordTest extends TestCase
{
    public function testOrganizationCreatedCapturesAttributes(): void
    {
        $actor = new User();
        $actor->setUsername('orgowner');
        $actor->setEmail('owner@example.com');
        $actor->setPassword('password');
        new \ReflectionProperty($actor, 'id')->setValue($actor, 42);

        $organizationId = new Ulid();
        $record = AuditRecord::organizationCreated($organizationId, 'acme', 'ACME Corp', $actor);

        self::assertSame(AuditRecordType::OrganizationCreated, $record->type);
        self::assertSame((string) $organizationId, $record->attributes['organization']['id']);
        self::assertSame('acme', $record->attributes['organization']['org_slug']);
        self::assertSame('ACME Corp', $record->attributes['organization']['org_name']);
        self::assertIsArray($record->attributes['actor']);
        self::assertSame(42, $record->attributes['actor']['id']);
        self::assertSame('orgowner', $record->attributes['actor']['username']);
        self::assertSame(42, $record->actorId);
    }

    public function testOrganizationCreatedWithoutActor(): void
    {
        $record = AuditRecord::organizationCreated(new Ulid(), 'acme', 'ACME Corp', null);

        self::assertSame(AuditRecordType::OrganizationCreated, $record->type);
        self::assertSame('unknown', $record->attributes['actor']);
        self::assertNull($record->actorId);
    }

    public function testOrganizationCreatedBelongsToOrganizationCategory(): void
    {
        self::assertSame('organization', AuditRecordType::OrganizationCreated->category());
    }

    public function testOrganizationRenamedCapturesBeforeAndAfter(): void
    {
        $organizationId = new Ulid();
        $record = AuditRecord::organizationRenamed($organizationId, 'ACME Inc', 'ACME Corp', null);

        self::assertSame(AuditRecordType::OrganizationRenamed, $record->type);
        self::assertSame((string) $organizationId, $record->attributes['organization_id']);
        self::assertSame('ACME Corp', $record->attributes['display_name_from']);
        self::assertSame('ACME Inc', $record->attributes['display_name_to']);
        self::assertSame('unknown', $record->attributes['actor']);
        self::assertSame('organization', AuditRecordType::OrganizationRenamed->category());
    }

    public function testOrganizationSlugChangedCapturesBeforeAndAfter(): void
    {
        $organizationId = new Ulid();
        $record = AuditRecord::organizationSlugChanged($organizationId, 'acme-inc', 'acme', null);

        self::assertSame(AuditRecordType::OrganizationSlugChanged, $record->type);
        self::assertSame((string) $organizationId, $record->attributes['organization_id']);
        self::assertSame('acme', $record->attributes['slug_from']);
        self::assertSame('acme-inc', $record->attributes['slug_to']);
        self::assertSame('organization', AuditRecordType::OrganizationSlugChanged->category());
    }
}
