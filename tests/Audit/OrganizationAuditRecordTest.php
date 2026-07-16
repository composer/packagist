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
use App\Tests\Fixtures\Fixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

class OrganizationAuditRecordTest extends TestCase
{
    use Fixtures;

    public function testOrganizationCreatedCapturesAttributes(): void
    {
        $organizationId = new Ulid();
        $record = AuditRecord::organizationCreated($organizationId, 'acme', 'ACME Corp', $this->actor());

        self::assertSame(AuditRecordType::OrganizationCreated, $record->type);
        self::assertSame((string) $organizationId, $record->attributes['organization']['id']);
        self::assertSame('acme', $record->attributes['organization']['org_slug']);
        self::assertSame('ACME Corp', $record->attributes['organization']['org_name']);
        self::assertIsArray($record->attributes['actor']);
        self::assertSame(42, $record->attributes['actor']['id']);
        self::assertSame('test', $record->attributes['actor']['username']);
        self::assertSame(42, $record->actorId);
    }

    public function testOrganizationCreatedBelongsToOrganizationCategory(): void
    {
        self::assertSame('organization', AuditRecordType::OrganizationCreated->category());
    }

    public function testOrganizationNameChangedCapturesBeforeAndAfter(): void
    {
        $organizationId = new Ulid();
        $record = AuditRecord::organizationNameChanged($organizationId, 'acme', 'ACME Inc', 'ACME Corp', $this->actor());

        self::assertSame(AuditRecordType::OrganizationNameChanged, $record->type);
        self::assertSame((string) $organizationId, $record->attributes['organization']['id']);
        self::assertSame('acme', $record->attributes['organization']['org_slug']);
        self::assertSame('ACME Inc', $record->attributes['organization']['org_name']);
        self::assertSame((string) $organizationId, (string) $record->organizationId);
        self::assertSame('ACME Corp', $record->attributes['org_name_from']);
        self::assertSame('ACME Inc', $record->attributes['org_name_to']);
        self::assertSame(42, $record->attributes['actor']['id']);
        self::assertSame('test', $record->attributes['actor']['username']);
        self::assertSame('organization', AuditRecordType::OrganizationNameChanged->category());
    }

    public function testOrganizationSlugChangedCapturesBeforeAndAfter(): void
    {
        $organizationId = new Ulid();
        $record = AuditRecord::organizationSlugChanged($organizationId, 'acme-inc', 'ACME Corp', 'acme', $this->actor());

        self::assertSame(AuditRecordType::OrganizationSlugChanged, $record->type);
        self::assertSame((string) $organizationId, $record->attributes['organization']['id']);
        self::assertSame('acme-inc', $record->attributes['organization']['org_slug']);
        self::assertSame('ACME Corp', $record->attributes['organization']['org_name']);
        self::assertSame((string) $organizationId, (string) $record->organizationId);
        self::assertSame('acme', $record->attributes['org_slug_from']);
        self::assertSame('acme-inc', $record->attributes['org_slug_to']);
        self::assertSame('organization', AuditRecordType::OrganizationSlugChanged->category());
    }

    private function actor(): User
    {
        $actor = $this->createUser();
        new \ReflectionProperty($actor, 'id')->setValue($actor, 42);

        return $actor;
    }
}
