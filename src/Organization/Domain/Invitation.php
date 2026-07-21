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

use App\Organization\Domain\Event\InvitationEvent;
use App\Organization\Domain\Event\UserInvitationAccepted;
use App\Organization\Domain\Event\UserInvitationDeclined;
use App\Organization\Domain\Event\UserInvitationExpired;
use App\Organization\Domain\Event\UserInvitationResent;
use App\Organization\Domain\Event\UserInvitationRevoked;
use App\Organization\Domain\Event\UserInvitationSent;
use App\Organization\Domain\Exception\EmailMismatchException;
use App\Organization\Domain\Exception\InvitationNotPendingException;
use App\Organization\Domain\Exception\NoPendingInvitationException;
use App\Organization\Domain\Exception\NoTeamSpecifiedException;
use App\Organization\Domain\Exception\PolicyNotMetException;
use App\Organization\Domain\Exception\TeamNotFoundException;
use App\Organization\EventStore\AbstractAggregate;
use App\Organization\EventStore\DomainEvent;
use App\Organization\EventStore\OrganizationEventType;
use Symfony\Component\Uid\Ulid;

/**
 * The Invitation aggregate: its own ULID stream, separate from the org. It owns the invitation's
 * lifecycle (pending → accepted / revoked / declined / expired) and the invariants around it.
 *
 * External facts are checked by {@see \App\Organization\InvitationManager} before a command reaches
 * here: that the org is active, that the target teams exist and belong to the org, that no duplicate
 * active invitation exists, and the raw-token match. The org-side membership created on acceptance lives
 * on the org aggregate; the manager appends both streams in one transaction.
 */
final class Invitation extends AbstractAggregate
{
    private Ulid $orgId;

    private string $email;

    private string $emailCanonical;

    private InvitationStatus $status;

    private \DateTimeImmutable $expiresAt;

    /** @var list<Ulid> */
    private array $teamIds = [];

    /**
     * Send a brand-new invitation. Duplicate-detection and team validation are the manager's job; the
     * aggregate only guards that at least one team was specified.
     *
     * @param list<Ulid> $teamIds
     *
     * @throws NoTeamSpecifiedException
     */
    public static function send(Ulid $id, Ulid $orgId, Email $email, array $teamIds, string $tokenHash, \DateTimeImmutable $expiresAt): self
    {
        if ($teamIds === []) {
            throw new NoTeamSpecifiedException('An invitation must specify at least one team.');
        }

        $invitation = new self($id);
        $invitation->record(new UserInvitationSent($id, $orgId, $email->value, $email->canonical, $teamIds, $tokenHash, $expiresAt));

        return $invitation;
    }

    /**
     * Re-issue the link and bump expiry. A logically-expired pending invitation cannot be revived.
     *
     * @throws NoPendingInvitationException
     */
    public function resend(string $newTokenHash, \DateTimeImmutable $newExpiresAt, \DateTimeImmutable $now): void
    {
        if (!$this->status->isPending() || $this->isExpired($now)) {
            throw new NoPendingInvitationException('There is no pending invitation to resend; send a new invitation instead.');
        }

        $this->record(new UserInvitationResent($this->id, $this->email, $newTokenHash, $newExpiresAt));
    }

    /**
     * An owner cancels the invitation. A no-op if it is no longer pending or has already expired; an
     * expired invitation is resolved by {@see markExpired}, not by revoking it.
     */
    public function revoke(\DateTimeImmutable $now): void
    {
        if (!$this->status->isPending() || $this->isExpired($now)) {
            return;
        }

        $this->record(new UserInvitationRevoked($this->id, $this->email));
    }

    /**
     * The invitee declines. A no-op if it is no longer pending or has already expired; the caller has
     * already validated the link token, and the invitee's account email must match the invited address.
     *
     * @throws EmailMismatchException
     */
    public function decline(string $userEmailCanonical, \DateTimeImmutable $now): void
    {
        if (!$this->status->isPending() || $this->isExpired($now)) {
            return;
        }

        $this->assertEmailMatches($userEmailCanonical);

        $this->record(new UserInvitationDeclined($this->id, $this->email));
    }

    /**
     * The invitee accepts. The caller has validated the link token and resolved which target teams still
     * exist ($acceptedTeamIds) and whether the owners team is among them ($ownersAmongTeams).
     *
     * @param list<Ulid> $acceptedTeamIds the still-existing target teams the user will join
     *
     * @throws InvitationNotPendingException
     * @throws NoPendingInvitationException the invitation has lapsed
     * @throws EmailMismatchException
     * @throws TeamNotFoundException        none of the target teams exist any more
     * @throws PolicyNotMetException        joining owners requires 2FA
     */
    public function accept(int $userId, string $userEmailCanonical, array $acceptedTeamIds, bool $ownersAmongTeams, bool $hasTwoFactor, \DateTimeImmutable $now): void
    {
        if (!$this->status->isPending()) {
            throw new InvitationNotPendingException('This invitation is no longer pending.');
        }

        if ($this->isExpired($now)) {
            throw new NoPendingInvitationException('This invitation has expired.');
        }

        $this->assertEmailMatches($userEmailCanonical);

        if ($acceptedTeamIds === []) {
            throw new TeamNotFoundException('None of the invited teams exist any more.');
        }

        if ($ownersAmongTeams && !$hasTwoFactor) {
            throw new PolicyNotMetException('You must enable two-factor authentication before becoming an owner.');
        }

        $this->record(new UserInvitationAccepted($this->id, $this->email, $userId, $acceptedTeamIds));
    }

    /**
     * Lazily mark a due invitation expired. A no-op if it is not pending or not yet past its expiry.
     */
    public function markExpired(\DateTimeImmutable $now): void
    {
        if (!$this->status->isPending() || !$this->isExpired($now)) {
            return;
        }

        $this->record(new UserInvitationExpired($this->id, $this->email));
    }

    /**
     * @param list<array{type: OrganizationEventType, payload: array<string, mixed>}> $history
     */
    public static function reconstitute(Ulid $id, array $history): self
    {
        $invitation = new self($id);
        $invitation->replay(array_map(
            static fn (array $row): DomainEvent => self::denormalize($id, $row['type'], $row['payload']),
            $history,
        ));

        return $invitation;
    }

    public function orgId(): Ulid
    {
        return $this->orgId;
    }

    public function emailCanonical(): string
    {
        return $this->emailCanonical;
    }

    public function status(): InvitationStatus
    {
        return $this->status;
    }

    /**
     * @return list<Ulid>
     */
    public function teamIds(): array
    {
        return $this->teamIds;
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt;
    }

    private function assertEmailMatches(string $userEmailCanonical): void
    {
        if ($userEmailCanonical !== $this->emailCanonical) {
            throw new EmailMismatchException('Your account email does not match the invited address.');
        }
    }

    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof UserInvitationSent => $this->applySent($event),
            $event instanceof UserInvitationResent => $this->expiresAt = $event->newExpiresAt,
            $event instanceof UserInvitationRevoked => $this->status = InvitationStatus::Revoked,
            $event instanceof UserInvitationDeclined => $this->status = InvitationStatus::Declined,
            $event instanceof UserInvitationAccepted => $this->status = InvitationStatus::Accepted,
            $event instanceof UserInvitationExpired => $this->status = InvitationStatus::Expired,
            default => throw new \LogicException('Unhandled invitation event: '.$event->eventType()->value),
        };
    }

    private function applySent(UserInvitationSent $event): void
    {
        $this->orgId = $event->organizationId;
        $this->email = $event->email;
        $this->emailCanonical = $event->emailCanonical;
        $this->teamIds = $event->teamIds;
        $this->expiresAt = $event->expiresAt;
        $this->status = InvitationStatus::Pending;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function denormalize(Ulid $id, OrganizationEventType $type, array $payload): InvitationEvent
    {
        return match ($type) {
            OrganizationEventType::UserInvitationSent => UserInvitationSent::fromPayload($id, $payload),
            OrganizationEventType::UserInvitationResent => UserInvitationResent::fromPayload($id, $payload),
            OrganizationEventType::UserInvitationRevoked => UserInvitationRevoked::fromPayload($id, $payload),
            OrganizationEventType::UserInvitationDeclined => UserInvitationDeclined::fromPayload($id, $payload),
            OrganizationEventType::UserInvitationAccepted => UserInvitationAccepted::fromPayload($id, $payload),
            OrganizationEventType::UserInvitationExpired => UserInvitationExpired::fromPayload($id, $payload),
            // The org-stream event types never appear in an invitation's history.
            OrganizationEventType::OrganizationCreated,
            OrganizationEventType::OrganizationNameChanged,
            OrganizationEventType::OrganizationSlugChanged,
            OrganizationEventType::TeamCreated,
            OrganizationEventType::TeamRenamed,
            OrganizationEventType::TeamMemberAdded,
            OrganizationEventType::TeamMemberRemoved,
            OrganizationEventType::TeamDeleted,
            OrganizationEventType::MemberJoined,
            OrganizationEventType::MemberRemoved,
            OrganizationEventType::MemberLeft => throw new \LogicException('Not an invitation-stream event: '.$type->value),
        };
    }
}
