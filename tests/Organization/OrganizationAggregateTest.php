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

namespace App\Tests\Organization;

use App\Organization\Domain\DisplayName;
use App\Organization\Domain\Event\MemberLeft;
use App\Organization\Domain\Event\MemberRemoved;
use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Event\OrganizationNameChanged;
use App\Organization\Domain\Event\OrganizationSlugChanged;
use App\Organization\Domain\Event\TeamCreated;
use App\Organization\Domain\Event\TeamDeleted;
use App\Organization\Domain\Event\TeamMemberAdded;
use App\Organization\Domain\Event\TeamMemberRemoved;
use App\Organization\Domain\Event\TeamRenamed;
use App\Organization\Domain\Exception\LastOwnerProtectedException;
use App\Organization\Domain\Exception\NotAMemberException;
use App\Organization\Domain\Exception\ReservedTeamNameException;
use App\Organization\Domain\Exception\TeamNameTakenException;
use App\Organization\Domain\Exception\TeamNotFoundException;
use App\Organization\Domain\Exception\TeamProtectedException;
use App\Organization\Domain\Exception\TwoFactorRequiredException;
use App\Organization\Domain\Organization;
use App\Organization\Domain\Slug;
use App\Organization\Domain\TeamName;
use App\Organization\EventStore\OrganizationEventType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

class OrganizationAggregateTest extends TestCase
{
    private const int OWNER = 1;

    public function testCreateBootstrapsOwnersTeamWithCreator(): void
    {
        $id = new Ulid();
        $ownersTeamId = new Ulid();
        $organization = Organization::create($id, new Slug('acme'), new DisplayName('ACME Corp'), $ownersTeamId, self::OWNER);

        $events = $organization->pullPendingEvents();

        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(OrganizationCreated::class, $event);
        self::assertTrue($id->equals($event->organizationId));
        self::assertSame('acme', $event->slug);
        self::assertSame('ACME Corp', $event->displayName);
        self::assertTrue($ownersTeamId->equals($event->ownersTeamId));
        self::assertSame(self::OWNER, $event->ownerId);
        self::assertTrue($organization->isOwner(self::OWNER));
        self::assertTrue($organization->isOrgMember(self::OWNER));
    }

    public function testReconstituteRebuildsStateFromHistory(): void
    {
        $id = new Ulid();
        $ownersTeamId = new Ulid();
        $created = Organization::create($id, new Slug('acme'), new DisplayName('ACME Corp'), $ownersTeamId, self::OWNER);
        $event = $created->pullPendingEvents()[0];
        self::assertInstanceOf(OrganizationCreated::class, $event);

        $reloaded = Organization::reconstitute($id, [
            ['type' => $event->eventType(), 'payload' => $event->toPayload()],
        ]);

        self::assertSame('acme', $reloaded->slug());
        self::assertSame('ACME Corp', $reloaded->displayName());
        self::assertSame(1, $reloaded->version());
        self::assertTrue($reloaded->isOwner(self::OWNER));
        // History replay must not leave events pending to be appended again.
        self::assertCount(0, $reloaded->pullPendingEvents());
    }

    public function testChangeNameRecordsEventWithPreviousName(): void
    {
        $organization = $this->created(new Ulid());

        $organization->changeName(new DisplayName('ACME Inc'));

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(OrganizationNameChanged::class, $events[0]);
        self::assertSame('ACME Inc', $events[0]->displayName);
        self::assertSame('ACME Corp', $events[0]->previousDisplayName);
        self::assertSame('ACME Inc', $organization->displayName());
    }

    public function testChangeSlugRecordsEventWithPreviousSlug(): void
    {
        $organization = $this->created(new Ulid());

        $organization->changeSlug(new Slug('acme-inc'));

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(OrganizationSlugChanged::class, $events[0]);
        self::assertSame('acme-inc', $events[0]->slug);
        self::assertSame('acme', $events[0]->previousSlug);
        self::assertSame('acme-inc', $organization->slug());
    }

    public function testChangeNameToSameNameIsNoop(): void
    {
        $organization = $this->created(new Ulid());

        $organization->changeName(new DisplayName('ACME Corp'));

        self::assertCount(0, $organization->pullPendingEvents());
    }

    public function testCreateTeamRecordsEvent(): void
    {
        $organization = $this->created(new Ulid());
        $teamId = new Ulid();

        $organization->createTeam($teamId, new TeamName('backend'));

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TeamCreated::class, $events[0]);
        self::assertTrue($teamId->equals($events[0]->teamId));
        self::assertSame('backend', $events[0]->name);
    }

    public function testCreateTeamRejectsReservedName(): void
    {
        $organization = $this->created(new Ulid());

        $this->expectException(ReservedTeamNameException::class);
        $organization->createTeam(new Ulid(), new TeamName('Owners'));
    }

    public function testCreateTeamRejectsDuplicateNameCaseInsensitively(): void
    {
        $organization = $this->created(new Ulid());
        $organization->createTeam(new Ulid(), new TeamName('backend'));

        $this->expectException(TeamNameTakenException::class);
        $organization->createTeam(new Ulid(), new TeamName('Backend'));
    }

    public function testRenameTeamRecordsEvent(): void
    {
        $organization = $this->created(new Ulid());
        $teamId = new Ulid();
        $organization->createTeam($teamId, new TeamName('backend'));
        $organization->pullPendingEvents();

        $organization->renameTeam($teamId, new TeamName('platform'));

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TeamRenamed::class, $events[0]);
        self::assertSame('platform', $events[0]->name);
        self::assertSame('backend', $events[0]->previousName);
    }

    public function testRenameTeamToSameNameIsNoop(): void
    {
        $organization = $this->created(new Ulid());
        $teamId = new Ulid();
        $organization->createTeam($teamId, new TeamName('backend'));
        $organization->pullPendingEvents();

        $organization->renameTeam($teamId, new TeamName('backend'));

        self::assertCount(0, $organization->pullPendingEvents());
    }

    public function testOwnersTeamCannotBeRenamed(): void
    {
        $ownersTeamId = new Ulid();
        $organization = $this->created($ownersTeamId);

        $this->expectException(TeamProtectedException::class);
        $organization->renameTeam($ownersTeamId, new TeamName('bosses'));
    }

    public function testOwnersTeamCannotBeDeleted(): void
    {
        $ownersTeamId = new Ulid();
        $organization = $this->created($ownersTeamId);

        $this->expectException(TeamProtectedException::class);
        $organization->deleteTeam($ownersTeamId);
    }

    public function testDeleteTeamRecordsEvent(): void
    {
        $organization = $this->created(new Ulid());
        $teamId = new Ulid();
        $organization->createTeam($teamId, new TeamName('backend'));
        $organization->pullPendingEvents();

        $organization->deleteTeam($teamId);

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TeamDeleted::class, $events[0]);
        self::assertSame('backend', $events[0]->name);
    }

    public function testDeleteMissingTeamThrows(): void
    {
        $organization = $this->created(new Ulid());

        $this->expectException(TeamNotFoundException::class);
        $organization->deleteTeam(new Ulid());
    }

    public function testAddTeamMemberRejectsNonOrgMember(): void
    {
        $ownersTeamId = new Ulid();
        $organization = $this->created($ownersTeamId);

        $this->expectException(NotAMemberException::class);
        $organization->addTeamMember($ownersTeamId, 999, true);
    }

    public function testAddingExistingMemberToOwnersRequiresTwoFactor(): void
    {
        $ownersTeamId = new Ulid();
        $customTeamId = new Ulid();
        // A second user already joined the org (via a custom team) — modelled directly in history.
        $organization = $this->reconstituteWith($ownersTeamId, [
            ['type' => OrganizationEventType::TeamCreated, 'payload' => ['teamId' => $customTeamId->toRfc4122(), 'name' => 'backend', 'kind' => 'custom']],
            ['type' => OrganizationEventType::TeamMemberAdded, 'payload' => ['teamId' => $customTeamId->toRfc4122(), 'userId' => 2]],
        ]);

        $this->expectException(TwoFactorRequiredException::class);
        $organization->addTeamMember($ownersTeamId, 2, false);
    }

    public function testAddingExistingMemberToOwnersWithTwoFactorRecordsEvent(): void
    {
        $ownersTeamId = new Ulid();
        $customTeamId = new Ulid();
        $organization = $this->reconstituteWith($ownersTeamId, [
            ['type' => OrganizationEventType::TeamCreated, 'payload' => ['teamId' => $customTeamId->toRfc4122(), 'name' => 'backend', 'kind' => 'custom']],
            ['type' => OrganizationEventType::TeamMemberAdded, 'payload' => ['teamId' => $customTeamId->toRfc4122(), 'userId' => 2]],
        ]);

        $organization->addTeamMember($ownersTeamId, 2, true);

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TeamMemberAdded::class, $events[0]);
        self::assertSame(2, $events[0]->userId);
    }

    public function testAddingAMemberAlreadyInTheTeamIsNoop(): void
    {
        $ownersTeamId = new Ulid();
        $organization = $this->created($ownersTeamId);

        // The creator is already in the owners team.
        $organization->addTeamMember($ownersTeamId, self::OWNER, true);

        self::assertCount(0, $organization->pullPendingEvents());
    }

    public function testRemovingTheLastOwnerFromTheOwnersTeamIsBlocked(): void
    {
        $ownersTeamId = new Ulid();
        $organization = $this->created($ownersTeamId);

        $this->expectException(LastOwnerProtectedException::class);
        $organization->removeTeamMember($ownersTeamId, self::OWNER);
    }

    public function testRemoveTeamMemberRecordsEventWhenNotLastOwner(): void
    {
        $ownersTeamId = new Ulid();
        // Two owners: removing one is allowed.
        $organization = $this->reconstituteWith($ownersTeamId, [
            ['type' => OrganizationEventType::TeamMemberAdded, 'payload' => ['teamId' => $ownersTeamId->toRfc4122(), 'userId' => 2]],
        ]);

        $organization->removeTeamMember($ownersTeamId, 2);

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TeamMemberRemoved::class, $events[0]);
        self::assertSame(2, $events[0]->userId);
    }

    public function testRemoveMemberOfWholeOrgIsBlockedForLastOwner(): void
    {
        $organization = $this->created(new Ulid());

        $this->expectException(LastOwnerProtectedException::class);
        $organization->removeMember(self::OWNER);
    }

    public function testRemoveMemberRejectsNonMember(): void
    {
        $organization = $this->created(new Ulid());

        $this->expectException(NotAMemberException::class);
        $organization->removeMember(999);
    }

    public function testLeaveIsBlockedForLastOwner(): void
    {
        $organization = $this->created(new Ulid());

        $this->expectException(LastOwnerProtectedException::class);
        $organization->leave(self::OWNER);
    }

    public function testMemberRemovedClearsAllTeamMembershipsOnReplay(): void
    {
        $ownersTeamId = new Ulid();
        $customTeamId = new Ulid();
        $id = new Ulid();
        $organization = Organization::reconstitute($id, [
            ['type' => OrganizationEventType::OrganizationCreated, 'payload' => $this->createdPayload($ownersTeamId)],
            ['type' => OrganizationEventType::TeamCreated, 'payload' => ['teamId' => $customTeamId->toRfc4122(), 'name' => 'backend', 'kind' => 'custom']],
            ['type' => OrganizationEventType::TeamMemberAdded, 'payload' => ['teamId' => $customTeamId->toRfc4122(), 'userId' => 2]],
            ['type' => OrganizationEventType::TeamMemberAdded, 'payload' => ['teamId' => $ownersTeamId->toRfc4122(), 'userId' => 2]],
            ['type' => OrganizationEventType::MemberRemoved, 'payload' => ['userId' => 2]],
        ]);

        self::assertFalse($organization->isOrgMember(2));
        self::assertTrue($organization->isOrgMember(self::OWNER));
    }

    public function testReconstituteReplaysMemberLeft(): void
    {
        $ownersTeamId = new Ulid();
        $organization = Organization::reconstitute(new Ulid(), [
            ['type' => OrganizationEventType::OrganizationCreated, 'payload' => $this->createdPayload($ownersTeamId)],
            ['type' => OrganizationEventType::TeamMemberAdded, 'payload' => ['teamId' => $ownersTeamId->toRfc4122(), 'userId' => 2]],
            ['type' => OrganizationEventType::MemberLeft, 'payload' => ['userId' => 2]],
        ]);

        self::assertFalse($organization->isOrgMember(2));
        self::assertTrue($organization->isOwner(self::OWNER));
    }

    private function created(Ulid $ownersTeamId): Organization
    {
        $organization = Organization::create(new Ulid(), new Slug('acme'), new DisplayName('ACME Corp'), $ownersTeamId, self::OWNER);
        $organization->pullPendingEvents();

        return $organization;
    }

    /**
     * @param list<array{type: OrganizationEventType, payload: array<string, mixed>}> $extra
     */
    private function reconstituteWith(Ulid $ownersTeamId, array $extra): Organization
    {
        return Organization::reconstitute(new Ulid(), [
            ['type' => OrganizationEventType::OrganizationCreated, 'payload' => $this->createdPayload($ownersTeamId)],
            ...$extra,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createdPayload(Ulid $ownersTeamId): array
    {
        return [
            'slug' => 'acme',
            'displayName' => 'ACME Corp',
            'ownersTeamId' => $ownersTeamId->toRfc4122(),
            'ownerId' => self::OWNER,
        ];
    }
}
