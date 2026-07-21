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
use App\Organization\Domain\Event\MemberJoined;
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
use App\Organization\Domain\Exception\TeamNameTakenException;
use App\Organization\Domain\Exception\TeamNotFoundException;
use App\Organization\Domain\Exception\TeamProtectedException;
use App\Organization\Domain\Exception\TwoFactorRequiredException;
use App\Organization\Domain\Organization;
use App\Organization\Domain\OrganizationTeamKind;
use App\Organization\Domain\Slug;
use App\Organization\Domain\TeamName;
use App\Organization\EventStore\OrganizationEventType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

class OrganizationAggregateTest extends TestCase
{
    private const int OWNER = 1;

    public function testCreateBootstrapsOwnersAndAllMembersTeamWithCreator(): void
    {
        $id = new Ulid();
        $ownersTeamId = new Ulid();
        $allMembersTeamId = new Ulid();
        $organization = Organization::create($id, new Slug('acme'), new DisplayName('ACME Corp'), $ownersTeamId, $allMembersTeamId, self::OWNER);

        $events = $organization->pullPendingEvents();

        // Creation is an explicit event sequence: the org, the founding owner joining, its two system
        // teams, then the owner's membership of each. Every fact is a first-class event so projections
        // replay them uniformly.
        self::assertCount(6, $events);

        self::assertInstanceOf(OrganizationCreated::class, $events[0]);
        self::assertTrue($id->equals($events[0]->organizationId));
        self::assertSame('acme', $events[0]->slug);
        self::assertSame('ACME Corp', $events[0]->displayName);
        self::assertTrue($ownersTeamId->equals($events[0]->ownersTeamId));
        self::assertTrue($allMembersTeamId->equals($events[0]->allMembersTeamId));

        self::assertInstanceOf(MemberJoined::class, $events[1]);
        self::assertSame(self::OWNER, $events[1]->userId);
        self::assertNull($events[1]->invitationId);

        self::assertInstanceOf(TeamCreated::class, $events[2]);
        self::assertTrue($ownersTeamId->equals($events[2]->teamId));
        self::assertSame(Organization::OWNERS_TEAM_NAME, $events[2]->name);
        self::assertSame(OrganizationTeamKind::System, $events[2]->kind);

        self::assertInstanceOf(TeamCreated::class, $events[3]);
        self::assertTrue($allMembersTeamId->equals($events[3]->teamId));
        self::assertSame(Organization::ALL_ORGANIZATION_MEMBERS_TEAM_NAME, $events[3]->name);
        self::assertSame(OrganizationTeamKind::System, $events[3]->kind);

        self::assertInstanceOf(TeamMemberAdded::class, $events[4]);
        self::assertTrue($ownersTeamId->equals($events[4]->teamId));
        self::assertSame(self::OWNER, $events[4]->userId);

        self::assertInstanceOf(TeamMemberAdded::class, $events[5]);
        self::assertTrue($allMembersTeamId->equals($events[5]->teamId));
        self::assertSame(self::OWNER, $events[5]->userId);

        self::assertTrue($organization->isOwner(self::OWNER));
        self::assertTrue($organization->isOrgMember(self::OWNER));
        self::assertTrue($allMembersTeamId->equals($organization->allMembersTeamId()));
    }

    public function testReconstituteRebuildsStateFromHistory(): void
    {
        $id = new Ulid();
        $ownersTeamId = new Ulid();
        $created = Organization::create($id, new Slug('acme'), new DisplayName('ACME Corp'), $ownersTeamId, new Ulid(), self::OWNER);
        $history = array_map(
            static fn ($event): array => ['type' => $event->eventType(), 'payload' => $event->toPayload()],
            $created->pullPendingEvents(),
        );

        $reloaded = Organization::reconstitute($id, $history);

        self::assertSame('acme', $reloaded->slug());
        self::assertSame('ACME Corp', $reloaded->displayName());
        self::assertSame(6, $reloaded->version());
        self::assertTrue($reloaded->isOwner(self::OWNER));
        self::assertTrue($reloaded->isOrgMember(self::OWNER));
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

    public function testAllMembersTeamCannotBeRenamed(): void
    {
        $allMembersTeamId = new Ulid();
        $organization = $this->created(new Ulid(), $allMembersTeamId);

        $this->expectException(TeamProtectedException::class);
        $organization->renameTeam($allMembersTeamId, new TeamName('everyone'));
    }

    public function testAllMembersTeamCannotBeAddedToManually(): void
    {
        $allMembersTeamId = new Ulid();
        $customTeamId = new Ulid();
        // A second user has joined the org via a custom team.
        $organization = $this->reconstituteWith(new Ulid(), [
            ['type' => OrganizationEventType::TeamCreated, 'payload' => ['teamId' => $customTeamId->toRfc4122(), 'name' => 'backend', 'kind' => 'custom']],
            ['type' => OrganizationEventType::TeamMemberAdded, 'payload' => ['teamId' => $customTeamId->toRfc4122(), 'userId' => 2]],
        ], $allMembersTeamId);

        $this->expectException(TeamProtectedException::class);
        $organization->addTeamMember($allMembersTeamId, 2, true);
    }

    public function testAllMembersTeamCannotBeRemovedFromManually(): void
    {
        $allMembersTeamId = new Ulid();
        $organization = $this->created(new Ulid(), $allMembersTeamId);

        // The creator is in the members team, but its roster is managed automatically.
        $this->expectException(TeamProtectedException::class);
        $organization->removeTeamMember($allMembersTeamId, self::OWNER);
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
            ...$this->bootstrapHistory($ownersTeamId),
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
            ...$this->bootstrapHistory($ownersTeamId),
            ['type' => OrganizationEventType::TeamMemberAdded, 'payload' => ['teamId' => $ownersTeamId->toRfc4122(), 'userId' => 2]],
            ['type' => OrganizationEventType::MemberLeft, 'payload' => ['userId' => 2]],
        ]);

        self::assertFalse($organization->isOrgMember(2));
        self::assertTrue($organization->isOwner(self::OWNER));
    }

    private function created(Ulid $ownersTeamId, ?Ulid $allMembersTeamId = null): Organization
    {
        $organization = Organization::create(new Ulid(), new Slug('acme'), new DisplayName('ACME Corp'), $ownersTeamId, $allMembersTeamId ?? new Ulid(), self::OWNER);
        $organization->pullPendingEvents();

        return $organization;
    }

    /**
     * @param list<array{type: OrganizationEventType, payload: array<string, mixed>}> $extra
     */
    private function reconstituteWith(Ulid $ownersTeamId, array $extra, ?Ulid $allMembersTeamId = null): Organization
    {
        return Organization::reconstitute(new Ulid(), [
            ...$this->bootstrapHistory($ownersTeamId, $allMembersTeamId),
            ...$extra,
        ]);
    }

    /**
     * The full five-event creation sequence a real org starts from: the org, its two system teams,
     * and the creator joining each. Reconstitution needs all of them to rebuild the bootstrapped state.
     *
     * @return list<array{type: OrganizationEventType, payload: array<string, mixed>}>
     */
    private function bootstrapHistory(Ulid $ownersTeamId, ?Ulid $allMembersTeamId = null): array
    {
        $allMembersTeamId ??= new Ulid();

        return [
            ['type' => OrganizationEventType::OrganizationCreated, 'payload' => [
                'slug' => 'acme',
                'displayName' => 'ACME Corp',
                'ownersTeamId' => $ownersTeamId->toRfc4122(),
                'allMembersTeamId' => $allMembersTeamId->toRfc4122(),
                'ownerId' => self::OWNER,
            ]],
            ['type' => OrganizationEventType::TeamCreated, 'payload' => ['teamId' => $ownersTeamId->toRfc4122(), 'name' => Organization::OWNERS_TEAM_NAME, 'kind' => 'system']],
            ['type' => OrganizationEventType::TeamCreated, 'payload' => ['teamId' => $allMembersTeamId->toRfc4122(), 'name' => Organization::ALL_ORGANIZATION_MEMBERS_TEAM_NAME, 'kind' => 'system']],
            ['type' => OrganizationEventType::TeamMemberAdded, 'payload' => ['teamId' => $ownersTeamId->toRfc4122(), 'userId' => self::OWNER]],
            ['type' => OrganizationEventType::TeamMemberAdded, 'payload' => ['teamId' => $allMembersTeamId->toRfc4122(), 'userId' => self::OWNER]],
        ];
    }
}
