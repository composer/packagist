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

use App\Entity\OrganizationInvitation;
use App\Entity\OrganizationInvitationRepository;
use App\Entity\OrganizationInvitationTeam;
use App\Entity\User;
use App\Entity\UserRepository;
use App\Organization\Domain\Event\InvitationEvent;
use App\Organization\Domain\Event\UserInvitationAccepted;
use App\Organization\Domain\Event\UserInvitationDeclined;
use App\Organization\Domain\Event\UserInvitationExpired;
use App\Organization\Domain\Event\UserInvitationResent;
use App\Organization\Domain\Event\UserInvitationRevoked;
use App\Organization\Domain\Event\UserInvitationSent;
use App\Organization\Domain\InvitationStatus;
use App\Organization\EventStore\RecordedEvent;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

/**
 * Projects the invitation stream into the `organization_invitation` and `organization_invitation_team`
 * read-model tables. These events are internal only and never reach the public transparency log; the
 * org-side membership created on acceptance is projected by {@see OrganizationReadModelProjector} from
 * the org stream's MemberJoined event.
 *
 * All projectors run for every event, so org-stream events are ignored here.
 */
final readonly class InvitationReadModelProjector implements Projector
{
    use DoctrineTrait;

    public function __construct(
        private ManagerRegistry $doctrine,
        private OrganizationInvitationRepository $invitations,
        private UserRepository $users,
    ) {
    }

    public function project(RecordedEvent $recorded): void
    {
        $event = $recorded->event;
        if (!$event instanceof InvitationEvent) {
            return;
        }

        match (true) {
            $event instanceof UserInvitationSent => $this->sent($recorded, $event),
            $event instanceof UserInvitationResent => $this->resent($recorded, $event),
            $event instanceof UserInvitationRevoked => $this->resolve($event->invitationId, InvitationStatus::Revoked, $recorded),
            $event instanceof UserInvitationDeclined => $this->resolve($event->invitationId, InvitationStatus::Declined, $recorded),
            $event instanceof UserInvitationAccepted => $this->resolve($event->invitationId, InvitationStatus::Accepted, $recorded),
            $event instanceof UserInvitationExpired => $this->resolve($event->invitationId, InvitationStatus::Expired, $recorded),
            default => throw new \LogicException('Unhandled invitation event: '.$event->eventType()->value),
        };

        $this->getEM()->flush();
    }

    private function sent(RecordedEvent $recorded, UserInvitationSent $event): void
    {
        $invitation = new OrganizationInvitation(
            $event->invitationId,
            $event->organizationId,
            $event->email,
            $event->emailCanonical,
            InvitationStatus::Pending,
            $event->tokenHash,
            $recorded->occurredAt,
            $event->expiresAt,
            $recorded->occurredAt,
            $this->user($recorded->actor->userId),
        );
        $this->getEM()->persist($invitation);

        foreach ($event->teamIds as $teamId) {
            $this->getEM()->persist(new OrganizationInvitationTeam($event->invitationId, $teamId));
        }
    }

    private function resent(RecordedEvent $recorded, UserInvitationResent $event): void
    {
        $invitation = $this->invitation($event->invitationId);
        $invitation->expiresAt = $event->newExpiresAt;
        $invitation->lastSentAt = $recorded->occurredAt;
        $invitation->tokenHash = $event->tokenHash;
    }

    private function resolve(Ulid $invitationId, InvitationStatus $status, RecordedEvent $recorded): void
    {
        $invitation = $this->invitation($invitationId);
        $invitation->status = $status;
        $invitation->resolvedAt = $recorded->occurredAt;
    }

    private function invitation(Ulid $id): OrganizationInvitation
    {
        $invitation = $this->invitations->find($id);
        if ($invitation === null) {
            throw new \LogicException('Organization invitation read model not found for '.$id->toRfc4122().'.');
        }

        return $invitation;
    }

    private function user(?int $userId): ?User
    {
        return $userId !== null ? $this->users->find($userId) : null;
    }
}
