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

namespace App\Organization\Projection;

use App\Entity\AuditRecord;
use App\Entity\AuditRecordRepository;
use App\Entity\Organization;
use App\Entity\OrganizationInvitationRepository;
use App\Entity\OrganizationRepository;
use App\Entity\OrganizationTeamRepository;
use App\Entity\User;
use App\Entity\UserRepository;
use App\Organization\Domain\Event\InvitationEvent;
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
use App\Organization\Domain\Event\UserInvitationAccepted;
use App\Organization\Domain\Event\UserInvitationDeclined;
use App\Organization\Domain\Event\UserInvitationExpired;
use App\Organization\Domain\Event\UserInvitationResent;
use App\Organization\Domain\Event\UserInvitationRevoked;
use App\Organization\Domain\Event\UserInvitationSent;
use App\Organization\EventStore\RecordedEvent;
use Symfony\Component\Uid\Ulid;

/**
 * Projects organization events into the public transparency log (`audit_log`). Membership and team
 * lifecycle changes are identified by username; invitation events additionally carry the invited email
 * (obfuscated at display time from anyone who is neither an auditor nor a member of the organization).
 */
final readonly class OrganizationAuditProjector implements Projector
{
    public function __construct(
        private AuditRecordRepository $auditRecordRepo,
        private UserRepository $users,
        private OrganizationRepository $organizationRepo,
        private OrganizationTeamRepository $organizationTeamRepo,
        private OrganizationInvitationRepository $invitations,
    ) {
    }

    public function project(RecordedEvent $recorded): void
    {
        $event = $recorded->event;

        // Invitation events live on their own stream (aggregate id = invitation ULID, not the org) and
        // an automation actor (expiry) has no user id, so they are projected before the actor check.
        if ($event instanceof InvitationEvent) {
            $this->auditRecordRepo->insert($this->invitationRecord($recorded, $event));

            return;
        }

        $actor = $this->user($recorded->actor->userId);
        if ($actor === null) {
            throw new \RuntimeException('Missing actor: ' . $recorded->actor->userId);
        }

        // OrganizationCreated carries its own slug/displayName and is projected before the
        // read-model row exists, so it must not read the read model. The system teams and the
        // creator's membership are set by their own TeamCreated / TeamMemberAdded events in the
        // same creation batch
        if ($event instanceof OrganizationCreated) {
            $this->auditRecordRepo->insert(AuditRecord::organizationCreated($event->organizationId, $event->slug, $event->displayName, $actor));

            return;
        }

        // Every other event happens after creation; slug and display name are unaffected by
        // team/member events (and by each other's change events), so the read model is safe here.
        $org = $this->organization($event->aggregateId());

        $this->auditRecordRepo->insert(
            match (true) {
                $event instanceof OrganizationNameChanged => AuditRecord::organizationNameChanged($event->organizationId, $org->slug, $event->displayName, $event->previousDisplayName, $actor),
                $event instanceof OrganizationSlugChanged => AuditRecord::organizationSlugChanged($event->organizationId, $event->slug, $org->displayName, $event->previousSlug, $actor),
                $event instanceof TeamCreated => AuditRecord::organizationTeamCreated($event->organizationId, $org->slug, $org->displayName, $event->name, $actor),
                $event instanceof TeamRenamed => AuditRecord::organizationTeamRenamed($event->organizationId, $org->slug, $org->displayName, $event->previousName, $event->name, $actor),
                $event instanceof TeamDeleted => AuditRecord::organizationTeamDeleted($event->organizationId, $org->slug, $org->displayName, $event->name, $actor),
                $event instanceof TeamMemberAdded => AuditRecord::organizationTeamMemberAdded($event->organizationId, $org->slug, $org->displayName, $this->teamName($event->teamId), $this->user($event->userId), $actor),
                $event instanceof MemberJoined => AuditRecord::organizationMemberJoined($event->organizationId, $org->slug, $org->displayName, $this->user($event->userId)),
                $event instanceof TeamMemberRemoved => AuditRecord::organizationTeamMemberRemoved($event->organizationId, $org->slug, $org->displayName, $this->teamName($event->teamId), $this->user($event->userId), $actor),
                $event instanceof MemberRemoved => AuditRecord::organizationMemberRemoved($event->organizationId, $org->slug, $org->displayName, $this->user($event->userId), $actor),
                $event instanceof MemberLeft => AuditRecord::organizationMemberLeft($event->organizationId, $org->slug, $org->displayName, $this->user($event->userId)),
                default => throw new \LogicException('Unhandled event: ' . $event->eventType()->value),
            }
        );
    }

    private function invitationRecord(RecordedEvent $recorded, InvitationEvent $event): AuditRecord
    {
        // Only UserInvitationSent carries the org id; the later events are resolved via the read model
        // row created by that first event (a prior, already-committed transaction).
        $orgId = $event instanceof UserInvitationSent
            ? $event->organizationId
            : $this->invitationOrganizationId($event->aggregateId());
        $org = $this->organization($orgId);
        $actor = $this->user($recorded->actor->userId);
        $email = $event->email();

        return match (true) {
            $event instanceof UserInvitationSent => AuditRecord::organizationInvitationSent($orgId, $org->slug, $org->displayName, $email, $actor),
            $event instanceof UserInvitationResent => AuditRecord::organizationInvitationResent($orgId, $org->slug, $org->displayName, $email, $actor),
            $event instanceof UserInvitationRevoked => AuditRecord::organizationInvitationRevoked($orgId, $org->slug, $org->displayName, $email, $actor),
            $event instanceof UserInvitationDeclined => AuditRecord::organizationInvitationDeclined($orgId, $org->slug, $org->displayName, $email, $actor),
            $event instanceof UserInvitationAccepted => AuditRecord::organizationInvitationAccepted($orgId, $org->slug, $org->displayName, $email, $actor),
            $event instanceof UserInvitationExpired => AuditRecord::organizationInvitationExpired($orgId, $org->slug, $org->displayName, $email),
            default => throw new \LogicException('Unhandled invitation event: '.$event->eventType()->value),
        };
    }

    private function invitationOrganizationId(Ulid $invitationId): Ulid
    {
        $invitation = $this->invitations->find($invitationId);
        if ($invitation === null) {
            throw new \LogicException('Organization invitation read model not found for '.$invitationId->toRfc4122().'.');
        }

        return $invitation->orgId;
    }

    private function teamName(Ulid $teamId): string
    {
        // A member add/remove event always follows the team's own creation event, so the read-model
        // row must exist by now; a miss signals an inconsistent projection rather than a normal state.
        $team = $this->organizationTeamRepo->find($teamId);
        if ($team === null) {
            throw new \LogicException('Organization team read model not found for '.$teamId->toRfc4122().'.');
        }

        return $team->name;
    }

    private function user(?int $userId): ?User
    {
        return $userId !== null ? $this->users->find($userId) : null;
    }

    private function organization(Ulid $id): Organization
    {
        $organization = $this->organizationRepo->find($id);
        if ($organization === null) {
            throw new \LogicException('Organization read model not found for '.$id->toRfc4122().'.');
        }

        return $organization;
    }
}
