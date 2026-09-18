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

use App\Entity\AuditRecord;
use App\Entity\User;
use App\Log\AuditLogEventType;
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

        self::assertSame(AuditLogEventType::OrganizationCreated, $record->type);
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
        self::assertSame('organization', AuditLogEventType::OrganizationCreated->category());
    }

    public function testOrganizationNameChangedCapturesBeforeAndAfter(): void
    {
        $organizationId = new Ulid();
        $record = AuditRecord::organizationNameChanged($organizationId, 'acme', 'ACME Inc', 'ACME Corp', $this->actor());

        self::assertSame(AuditLogEventType::OrganizationNameChanged, $record->type);
        self::assertSame((string) $organizationId, $record->attributes['organization']['id']);
        self::assertSame('acme', $record->attributes['organization']['org_slug']);
        self::assertSame('ACME Inc', $record->attributes['organization']['org_name']);
        self::assertSame((string) $organizationId, (string) $record->organizationId);
        self::assertSame('ACME Corp', $record->attributes['org_name_from']);
        self::assertSame('ACME Inc', $record->attributes['org_name_to']);
        self::assertSame(42, $record->attributes['actor']['id']);
        self::assertSame('test', $record->attributes['actor']['username']);
        self::assertSame('organization', AuditLogEventType::OrganizationNameChanged->category());
    }

    public function testOrganizationSlugChangedCapturesBeforeAndAfter(): void
    {
        $organizationId = new Ulid();
        $record = AuditRecord::organizationSlugChanged($organizationId, 'acme-inc', 'ACME Corp', 'acme', $this->actor());

        self::assertSame(AuditLogEventType::OrganizationSlugChanged, $record->type);
        self::assertSame((string) $organizationId, $record->attributes['organization']['id']);
        self::assertSame('acme-inc', $record->attributes['organization']['org_slug']);
        self::assertSame('ACME Corp', $record->attributes['organization']['org_name']);
        self::assertSame((string) $organizationId, (string) $record->organizationId);
        self::assertSame('acme', $record->attributes['org_slug_from']);
        self::assertSame('acme-inc', $record->attributes['org_slug_to']);
        self::assertSame('organization', AuditLogEventType::OrganizationSlugChanged->category());
    }

    public function testTeamCreatedCapturesTeamName(): void
    {
        $organizationId = new Ulid();
        $record = AuditRecord::organizationTeamCreated($organizationId, 'acme', 'ACME Corp', 'backend', null);

        self::assertSame(AuditLogEventType::OrganizationTeamCreated, $record->type);
        self::assertSame('backend', $record->attributes['team_name']);
        self::assertSame((string) $organizationId, (string) $record->organizationId);
        self::assertSame('organization', AuditLogEventType::OrganizationTeamCreated->category());
    }

    public function testTeamRenamedCapturesBeforeAndAfter(): void
    {
        $record = AuditRecord::organizationTeamRenamed(new Ulid(), 'acme', 'ACME Corp', 'backend', 'platform', null);

        self::assertSame(AuditLogEventType::OrganizationTeamRenamed, $record->type);
        self::assertSame('backend', $record->attributes['team_name_from']);
        self::assertSame('platform', $record->attributes['team_name_to']);
    }

    public function testTeamMemberAddedCapturesMemberAndTeam(): void
    {
        $member = new User();
        $member->setUsername('alice');
        $member->setEmail('alice@example.com');
        $member->setPassword('password');
        new \ReflectionProperty($member, 'id')->setValue($member, 7);

        $record = AuditRecord::organizationTeamMemberAdded(new Ulid(), 'acme', 'ACME Corp', 'backend', $member, null);

        self::assertSame(AuditLogEventType::OrganizationTeamMemberAdded, $record->type);
        self::assertSame('backend', $record->attributes['team_name']);
        self::assertSame(7, $record->attributes['user']['id']);
        self::assertSame('alice', $record->attributes['user']['username']);
        self::assertSame(7, $record->userId);
    }

    public function testMemberLeftUsesMemberAsActor(): void
    {
        $member = new User();
        $member->setUsername('alice');
        $member->setEmail('alice@example.com');
        $member->setPassword('password');
        new \ReflectionProperty($member, 'id')->setValue($member, 7);

        $record = AuditRecord::organizationMemberLeft(new Ulid(), 'acme', 'ACME Corp', $member, $member);

        self::assertSame(AuditLogEventType::OrganizationMemberLeft, $record->type);
        self::assertSame('alice', $record->attributes['user']['username']);
        self::assertSame('alice', $record->attributes['actor']['username']);
        self::assertSame(7, $record->actorId);
    }

    public function testMemberJoinedUsesMemberAsActor(): void
    {
        $member = new User();
        $member->setUsername('alice');
        $member->setEmail('alice@example.com');
        $member->setPassword('password');
        new \ReflectionProperty($member, 'id')->setValue($member, 7);

        $organizationId = new Ulid();
        $record = AuditRecord::organizationMemberJoined($organizationId, 'acme', 'ACME Corp', $member, $member);

        self::assertSame(AuditLogEventType::OrganizationMemberJoined, $record->type);
        self::assertSame('organization', AuditLogEventType::OrganizationMemberJoined->category());
        self::assertSame('alice', $record->attributes['user']['username']);
        // Accepting an invitation or founding the org: the member drove their own join. The invited
        // email never appears.
        self::assertSame('alice', $record->attributes['actor']['username']);
        self::assertArrayNotHasKey('email', $record->attributes);
        self::assertSame(7, $record->userId);
        self::assertSame(7, $record->actorId);
    }

    public function testMemberJoinedKeepsTheActorSeparateFromTheMember(): void
    {
        $member = new User();
        $member->setUsername('alice');
        $member->setEmail('alice@example.com');
        $member->setPassword('password');
        new \ReflectionProperty($member, 'id')->setValue($member, 7);

        // A join driven by someone else stays attributable to them, and to them alone as actor.
        $record = AuditRecord::organizationMemberJoined(new Ulid(), 'acme', 'ACME Corp', $member, $this->actor());

        self::assertSame('alice', $record->attributes['user']['username']);
        self::assertSame('test', $record->attributes['actor']['username']);
        self::assertSame(7, $record->userId);
        self::assertSame(42, $record->actorId);
    }

    public function testInvitationSentCapturesEmailAndActor(): void
    {
        $organizationId = new Ulid();
        $record = AuditRecord::organizationInvitationSent($organizationId, 'acme', 'ACME Corp', 'alice@example.org', $this->actor());

        self::assertSame(AuditLogEventType::OrganizationInvitationSent, $record->type);
        self::assertSame('organization', AuditLogEventType::OrganizationInvitationSent->category());
        self::assertSame('acme', $record->attributes['organization']['org_slug']);
        self::assertSame('alice@example.org', $record->attributes['email']);
        self::assertSame('test', $record->attributes['actor']['username']);
        self::assertSame((string) $organizationId, (string) $record->organizationId);
        self::assertSame(42, $record->actorId);
    }

    public function testInvitationAcceptedRecordsInviteeAsActor(): void
    {
        $record = AuditRecord::organizationInvitationAccepted(new Ulid(), 'acme', 'ACME Corp', 'alice@example.org', $this->actor());

        self::assertSame(AuditLogEventType::OrganizationInvitationAccepted, $record->type);
        self::assertSame('alice@example.org', $record->attributes['email']);
        self::assertSame('test', $record->attributes['actor']['username']);
        self::assertSame(42, $record->actorId);
    }

    public function testInvitationExpiredHasNoActor(): void
    {
        $record = AuditRecord::organizationInvitationExpired(new Ulid(), 'acme', 'ACME Corp', 'alice@example.org');

        self::assertSame(AuditLogEventType::OrganizationInvitationExpired, $record->type);
        self::assertSame('alice@example.org', $record->attributes['email']);
        // Expiry is recorded by automation, so there is no acting user.
        self::assertSame('automation', $record->attributes['actor']);
        self::assertNull($record->actorId);
    }

    /**
     * The invited email is only ever kept in attributes, never fed to the search index.
     */
    public function testInvitationEmailIsNotIndexed(): void
    {
        $record = AuditRecord::organizationInvitationSent(new Ulid(), 'acme', 'ACME Corp', 'alice@example.org', $this->actor());

        $names = array_column($record->getSearchTerms(), 'name');
        self::assertNotContains('alice@example.org', $names);
    }

    private function actor(): User
    {
        $actor = $this->createUser();
        new \ReflectionProperty($actor, 'id')->setValue($actor, 42);

        return $actor;
    }
}
