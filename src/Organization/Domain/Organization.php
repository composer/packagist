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

namespace App\Organization\Domain;

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
use App\Organization\EventStore\AbstractAggregate;
use App\Organization\EventStore\DomainEvent;
use App\Organization\EventStore\OrganizationEventType;
use Symfony\Component\Uid\Ulid;

/**
 * The Organization aggregate and its consistency boundary. Teams and memberships live inside it,
 * so cross-member invariants (last-owner guard, team-name uniqueness) are enforced in one
 * transaction. External facts (slug uniqueness, 2FA, rate limits) are checked by the application
 * service before a command reaches here.
 *
 * This is the write-side model. The projection is {@see \App\Entity\Organization} and the team
 * read models {@see \App\Entity\OrganizationTeam} / {@see \App\Entity\OrganizationTeamMember}.
 */
final class Organization extends AbstractAggregate
{
    /** The reserved system team name; created only via bootstrap, never through TeamCreated. */
    public const string OWNERS_TEAM_NAME = 'Owners';

    /** The reserved system team every org member belongs to; created only via bootstrap. */
    public const string ALL_ORGANIZATION_MEMBERS_TEAM_NAME = 'All organization members';

    private string $slug;

    private string $displayName;

    // Groundwork for org deletion (not yet implemented).
    private bool $deleted = false;

    private ?Ulid $ownersTeamId = null;

    private ?Ulid $allMembersTeamId = null;

    /** @var array<string, array{kind: OrganizationTeamKind, name: string}> teamId (rfc4122) => team */
    private array $teams = [];

    /** @var array<string, list<int>> teamId (rfc4122) => member user ids */
    private array $teamMembers = [];

    /** @var list<int> org member user ids, tracked directly from MemberJoined / MemberLeft / MemberRemoved */
    private array $members = [];

    public static function create(Ulid $id, Slug $slug, DisplayName $displayName, Ulid $ownersTeamId, Ulid $allMembersTeamId, int $ownerId): self
    {
        $organization = new self($id);

        // Bootstrap the org through multiple events: the org itself, the founding owner joining, then its
        // two system teams and the owner's membership of each. Every fact is a first-class event (rather
        // than all being covered by a single OrganizationCreated event); MemberJoined is the org-level
        // membership fact and the TeamMemberAdded events place the owner in the system teams.
        $organization->record(new OrganizationCreated($id, $slug->value, $displayName->value, $ownersTeamId, $allMembersTeamId));
        $organization->record(new MemberJoined($id, $ownerId, null));
        $organization->record(new TeamCreated($id, $ownersTeamId, self::OWNERS_TEAM_NAME, OrganizationTeamKind::System));
        $organization->record(new TeamCreated($id, $allMembersTeamId, self::ALL_ORGANIZATION_MEMBERS_TEAM_NAME, OrganizationTeamKind::System));
        $organization->record(new TeamMemberAdded($id, $ownersTeamId, $ownerId));
        $organization->record(new TeamMemberAdded($id, $allMembersTeamId, $ownerId));

        return $organization;
    }

    /**
     * Change the display name. No-op when the name is unchanged.
     */
    public function changeName(DisplayName $displayName): void
    {
        if ($this->displayName === $displayName->value) {
            return;
        }

        $this->record(new OrganizationNameChanged($this->id, $displayName->value, $this->displayName));
    }

    public function changeSlug(Slug $slug): void
    {
        if ($this->slug === $slug->value) {
            return;
        }

        $this->record(new OrganizationSlugChanged($this->id, $slug->value, $this->slug));
    }

    /**
     * Create a custom team. The `owners` team is bootstrapped by creation, not this path.
     *
     * @throws TeamNameTakenException    another team already uses this name (case-insensitive)
     */
    public function createTeam(Ulid $teamId, TeamName $name): void
    {
        $this->assertNameAvailable($name, null);

        $this->record(new TeamCreated($this->id, $teamId, $name->value));
    }

    /**
     * Rename a custom team. No-op when the name is unchanged.
     *
     * @throws TeamNotFoundException
     * @throws TeamProtectedException    the `owners` team cannot be renamed
     * @throws TeamNameTakenException
     */
    public function renameTeam(Ulid $teamId, TeamName $name): void
    {
        $this->assertCustomTeam($teamId);

        if ($this->teams[$teamId->toRfc4122()]['name'] === $name->value) {
            return;
        }

        $this->assertNameAvailable($name, $teamId);

        $this->record(new TeamRenamed($this->id, $teamId, $name->value, $this->teams[$teamId->toRfc4122()]['name']));
    }

    /**
     * Delete a custom team and cascade its memberships.
     *
     * @throws TeamNotFoundException
     * @throws TeamProtectedException the `owners` team cannot be deleted
     */
    public function deleteTeam(Ulid $teamId): void
    {
        $this->assertCustomTeam($teamId);

        $this->record(new TeamDeleted($this->id, $teamId, $this->teams[$teamId->toRfc4122()]['name']));
    }

    /**
     * Add an existing org member to a further team. No-op if already in the team.
     *
     * @param bool $targetHasTwoFactor whether the target user has 2FA enabled (checked by the caller)
     *
     * @throws TeamNotFoundException
     * @throws TeamProtectedException    the `all organization members` team is managed automatically
     * @throws NotAMemberException       the target has not joined the org (joining is invitation-only)
     * @throws TwoFactorRequiredException adding to `owners` requires the target to have 2FA
     */
    public function addTeamMember(Ulid $teamId, int $userId, bool $targetHasTwoFactor): void
    {
        $this->assertTeamExists($teamId);
        $this->assertNotAllMembersTeam($teamId);

        if (!$this->isOrgMember($userId)) {
            throw new NotAMemberException('The user is not a member of this organization.');
        }

        if ($this->isInTeam($teamId, $userId)) {
            return;
        }

        if ($teamId->equals($this->ownersTeamId) && !$targetHasTwoFactor) {
            throw new TwoFactorRequiredException('The user must enable two-factor authentication before becoming an owner.');
        }

        $this->record(new TeamMemberAdded($this->id, $teamId, $userId));
    }

    /**
     * Remove a user from a single team.
     *
     * @throws TeamNotFoundException
     * @throws TeamProtectedException       the `all organization members` team is managed automatically
     * @throws NotAMemberException          the user is not in the team
     * @throws LastOwnerProtectedException  would empty the `owners` team
     */
    public function removeTeamMember(Ulid $teamId, int $userId): void
    {
        $this->assertTeamExists($teamId);
        $this->assertNotAllMembersTeam($teamId);

        if (!$this->isInTeam($teamId, $userId)) {
            throw new NotAMemberException('The user is not a member of this team.');
        }

        if ($teamId->equals($this->ownersTeamId) && $this->ownerCount() === 1) {
            throw new LastOwnerProtectedException('The last owner cannot be removed from the organization.');
        }

        $this->record(new TeamMemberRemoved($this->id, $teamId, $userId));
    }

    /**
     * A new member joins by accepting an invitation. They are added to the given target teams plus the
     * automatically-managed `all organization members` team. The caller ({@see \App\Organization\InvitationManager})
     * resolves the still-existing target teams and enforces the invitation-side checks (email match, 2FA
     * for owners); this records the org-side membership.
     *
     * @param list<Ulid> $targetTeamIds the still-existing target teams (must be non-empty)
     *
     * @throws TeamNotFoundException none of the target teams exist any more
     */
    public function joinViaInvitation(int $userId, array $targetTeamIds, Ulid $invitationId): void
    {
        $teamIds = $this->existingTeamsAmong($targetTeamIds);
        if ($teamIds === []) {
            throw new TeamNotFoundException('None of the invited teams exist any more.');
        }

        // Every org member belongs to the all-members team; add it alongside the target teams.
        if ($this->allMembersTeamId !== null && !$this->containsTeam($teamIds, $this->allMembersTeamId)) {
            $teamIds[] = $this->allMembersTeamId;
        }

        // Skip any team the user is somehow already in, so a re-run cannot double-add.
        $teamIds = array_values(array_filter($teamIds, fn (Ulid $teamId): bool => !$this->isInTeam($teamId, $userId)));
        if ($teamIds === []) {
            return;
        }

        $this->record(new MemberJoined($this->id, $userId, $invitationId));
        foreach ($teamIds as $teamId) {
            $this->record(new TeamMemberAdded($this->id, $teamId, $userId));
        }
    }

    /**
     * The subset of the given team ids that currently exist in the org, preserving order. Used by the
     * invitation flow to resolve which target teams a new member is actually added to.
     *
     * @param list<Ulid> $teamIds
     *
     * @return list<Ulid>
     */
    public function existingTeamsAmong(array $teamIds): array
    {
        return array_values(array_filter($teamIds, fn (Ulid $teamId): bool => isset($this->teams[$teamId->toRfc4122()])));
    }

    /**
     * @param list<Ulid> $teamIds
     */
    private function containsTeam(array $teamIds, Ulid $needle): bool
    {
        foreach ($teamIds as $teamId) {
            if ($teamId->equals($needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove a user from the entire org (all their teams at once).
     *
     * @throws NotAMemberException
     * @throws LastOwnerProtectedException
     */
    public function removeMember(int $userId): void
    {
        $this->assertRemovableMember($userId);

        $this->record(new MemberRemoved($this->id, $userId));
    }

    /**
     * A member voluntarily leaves the org entirely (all teams).
     *
     * @throws NotAMemberException
     * @throws LastOwnerProtectedException the last owner must appoint another owner first
     */
    public function leave(int $userId): void
    {
        $this->assertRemovableMember($userId);

        $this->record(new MemberLeft($this->id, $userId));
    }

    /**
     * @param list<array{type: OrganizationEventType, payload: array<string, mixed>}> $history
     */
    public static function reconstitute(Ulid $id, array $history): self
    {
        $organization = new self($id);
        $organization->replay(array_map(
            static fn (array $row): DomainEvent => self::denormalize($id, $row['type'], $row['payload']),
            $history,
        ));

        return $organization;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function ownersTeamId(): ?Ulid
    {
        return $this->ownersTeamId;
    }

    public function allMembersTeamId(): ?Ulid
    {
        return $this->allMembersTeamId;
    }

    public function isOwner(int $userId): bool
    {
        return $this->ownersTeamId !== null && $this->isInTeam($this->ownersTeamId, $userId);
    }

    public function isOrgMember(int $userId): bool
    {
        return \in_array($userId, $this->members, true);
    }

    private function isInTeam(Ulid $teamId, int $userId): bool
    {
        return \in_array($userId, $this->teamMembers[$teamId->toRfc4122()] ?? [], true);
    }

    private function ownerCount(): int
    {
        return \count($this->teamMembers[$this->ownersTeamId?->toRfc4122()] ?? []);
    }

    private function assertTeamExists(Ulid $teamId): void
    {
        if (!isset($this->teams[$teamId->toRfc4122()])) {
            throw new TeamNotFoundException('The team does not exist.');
        }
    }

    private function assertCustomTeam(Ulid $teamId): void
    {
        $this->assertTeamExists($teamId);

        if ($this->teams[$teamId->toRfc4122()]['kind'] !== OrganizationTeamKind::Custom) {
            throw new TeamProtectedException('This team is protected and cannot be renamed or deleted.');
        }
    }

    /**
     * The `all organization members` team's roster is derived from org membership, so it cannot be
     * added to or removed from directly. Use {@see addTeamMember}/{@see removeTeamMember} on other
     * teams and {@see leave}/{@see removeMember} to change org membership.
     *
     * @throws TeamProtectedException
     */
    private function assertNotAllMembersTeam(Ulid $teamId): void
    {
        if ($teamId->equals($this->allMembersTeamId)) {
            throw new TeamProtectedException('Membership of the "All organization members" team is managed automatically.');
        }
    }

    private function assertNameAvailable(TeamName $name, ?Ulid $exceptTeamId): void
    {
        $candidate = mb_strtolower($name->value);
        foreach ($this->teams as $teamId => $team) {
            if ($exceptTeamId !== null && $teamId === $exceptTeamId->toRfc4122()) {
                continue;
            }

            if (mb_strtolower($team['name']) === $candidate) {
                throw new TeamNameTakenException(sprintf('A team named "%s" already exists in this organization.', $name->value));
            }
        }
    }

    private function assertRemovableMember(int $userId): void
    {
        if (!$this->isOrgMember($userId)) {
            throw new NotAMemberException('The user is not a member of this organization.');
        }

        if ($this->ownersTeamId !== null && $this->isInTeam($this->ownersTeamId, $userId) && $this->ownerCount() === 1) {
            throw new LastOwnerProtectedException('The last owner cannot leave or be removed from the organization.');
        }
    }

    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof OrganizationCreated => $this->applyCreated($event),
            $event instanceof OrganizationNameChanged => $this->displayName = $event->displayName,
            $event instanceof OrganizationSlugChanged => $this->slug = $event->slug,
            $event instanceof TeamCreated => $this->applyTeamCreated($event),
            $event instanceof TeamRenamed => $this->teams[$event->teamId->toRfc4122()]['name'] = $event->name,
            $event instanceof TeamMemberAdded => $this->teamMembers[$event->teamId->toRfc4122()][] = $event->userId,
            $event instanceof TeamMemberRemoved => $this->applyTeamMemberRemoved($event),
            $event instanceof TeamDeleted => $this->applyTeamDeleted($event),
            $event instanceof MemberJoined => $this->applyMemberJoined($event),
            $event instanceof MemberRemoved => $this->applyMemberGone($event->userId),
            $event instanceof MemberLeft => $this->applyMemberGone($event->userId),
            default => throw new \LogicException('Unhandled organization event: '.$event->eventType()->value),
        };
    }

    private function applyCreated(OrganizationCreated $event): void
    {
        // Membership and the system teams are established by the MemberJoined / TeamCreated / TeamMemberAdded events that follow in the creation batch.
        $this->slug = $event->slug;
        $this->displayName = $event->displayName;
        $this->deleted = false;
        $this->ownersTeamId = $event->ownersTeamId;
        $this->allMembersTeamId = $event->allMembersTeamId;
    }

    private function applyTeamCreated(TeamCreated $event): void
    {
        $this->teams[$event->teamId->toRfc4122()] = ['kind' => $event->kind, 'name' => $event->name];
        $this->teamMembers[$event->teamId->toRfc4122()] = [];
    }

    private function applyTeamMemberRemoved(TeamMemberRemoved $event): void
    {
        $key = $event->teamId->toRfc4122();
        $this->teamMembers[$key] = array_values(array_filter(
            $this->teamMembers[$key] ?? [],
            static fn (int $id): bool => $id !== $event->userId,
        ));
    }

    private function applyTeamDeleted(TeamDeleted $event): void
    {
        unset($this->teams[$event->teamId->toRfc4122()], $this->teamMembers[$event->teamId->toRfc4122()]);
    }

    private function applyMemberJoined(MemberJoined $event): void
    {
        // Idempotent: a re-run, or joining a further team while already a member, must not duplicate.
        if (!\in_array($event->userId, $this->members, true)) {
            $this->members[] = $event->userId;
        }
    }

    private function applyMemberGone(int $userId): void
    {
        $this->members = array_values(array_filter(
            $this->members,
            static fn (int $id): bool => $id !== $userId,
        ));

        foreach ($this->teamMembers as $key => $members) {
            $this->teamMembers[$key] = array_values(array_filter(
                $members,
                static fn (int $id): bool => $id !== $userId,
            ));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function denormalize(Ulid $id, OrganizationEventType $type, array $payload): DomainEvent
    {
        return match ($type) {
            OrganizationEventType::OrganizationCreated => OrganizationCreated::fromPayload($id, $payload),
            OrganizationEventType::OrganizationNameChanged => OrganizationNameChanged::fromPayload($id, $payload),
            OrganizationEventType::OrganizationSlugChanged => OrganizationSlugChanged::fromPayload($id, $payload),
            OrganizationEventType::TeamCreated => TeamCreated::fromPayload($id, $payload),
            OrganizationEventType::TeamRenamed => TeamRenamed::fromPayload($id, $payload),
            OrganizationEventType::TeamMemberAdded => TeamMemberAdded::fromPayload($id, $payload),
            OrganizationEventType::TeamMemberRemoved => TeamMemberRemoved::fromPayload($id, $payload),
            OrganizationEventType::TeamDeleted => TeamDeleted::fromPayload($id, $payload),
            OrganizationEventType::MemberJoined => MemberJoined::fromPayload($id, $payload),
            OrganizationEventType::MemberRemoved => MemberRemoved::fromPayload($id, $payload),
            OrganizationEventType::MemberLeft => MemberLeft::fromPayload($id, $payload),
            // Invitation-stream events belong to the Invitation aggregate and never appear in an org's
            // history, so reconstituting an org never denormalizes them.
            OrganizationEventType::UserInvitationSent,
            OrganizationEventType::UserInvitationResent,
            OrganizationEventType::UserInvitationRevoked,
            OrganizationEventType::UserInvitationDeclined,
            OrganizationEventType::UserInvitationAccepted,
            OrganizationEventType::UserInvitationExpired => throw new \LogicException('Not an organization-stream event: '.$type->value),
        };
    }
}
