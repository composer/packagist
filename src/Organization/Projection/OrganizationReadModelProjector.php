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

use App\Entity\Organization;
use App\Entity\OrganizationRepository;
use App\Entity\OrganizationStatus;
use App\Entity\OrganizationTeam;
use App\Entity\OrganizationMember;
use App\Entity\OrganizationMemberRepository;
use App\Entity\OrganizationTeamMember;
use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\OrganizationTeamRepository;
use App\Entity\SlugReservation;
use App\Entity\SlugReservationKind;
use App\Entity\SlugReservationRepository;
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
use App\Organization\EventStore\RecordedEvent;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

/**
 * Projects the organization event stream into the `organization`, `organization_team` and
 * `organization_team_member` read-model tables. Unique constraints (slug, team name) turn a
 * concurrent duplicate into a transaction rollback, which the application service maps to a
 * SlugTaken / TeamNameTaken error.
 */
final readonly class OrganizationReadModelProjector implements Projector
{
    use DoctrineTrait;

    public function __construct(
        private ManagerRegistry $doctrine,
        private UserRepository $userRepo,
        private OrganizationRepository $organizationRepo,
        private SlugReservationRepository $slugReservationRepo,
        private OrganizationTeamRepository $organizationTeamRepo,
        private OrganizationTeamMemberRepository $organizationTeamMemberRepo,
        private OrganizationMemberRepository $organizationMemberRepo,
    ) {
    }

    public function project(RecordedEvent $recorded): void
    {
        $event = $recorded->event;

        // Invitation-stream events have their own read-model projector and are internal only.
        if ($event instanceof InvitationEvent) {
            return;
        }

        match (true) {
            $event instanceof OrganizationCreated => $this->organizationCreated($recorded, $event),
            $event instanceof OrganizationNameChanged => $this->organizationNameChanged($event),
            $event instanceof OrganizationSlugChanged => $this->organizationSlugChanged($recorded, $event),
            $event instanceof TeamCreated => $this->teamCreated($recorded, $event),
            $event instanceof TeamRenamed => $this->team($event->teamId)->name = $event->name,
            $event instanceof TeamMemberAdded => $this->teamMemberAdded($recorded, $event),
            $event instanceof MemberJoined => $this->memberJoined($recorded, $event),
            $event instanceof TeamMemberRemoved => $this->removeMembership($event->teamId, $event->userId),
            $event instanceof TeamDeleted => $this->teamDeleted($event),
            $event instanceof MemberRemoved => $this->memberGone($event->organizationId, $event->userId),
            $event instanceof MemberLeft => $this->memberGone($event->organizationId, $event->userId),
            default => throw new \LogicException('Unhandled event: ' . $event->eventType()->value),
        };

        $this->getEM()->flush();
    }

    private function organizationCreated(RecordedEvent $recorded, OrganizationCreated $event): void
    {
        // NOTE: the two system teams and the owner's membership are set up as their own
        // TeamCreated / TeamMemberAdded events later in the same creation batch
        $organization = new Organization(
            $event->organizationId,
            $event->slug,
            $event->displayName,
            OrganizationStatus::Active,
            $recorded->occurredAt,
            $event->ownersTeamId,
            $event->allMembersTeamId,
        );
        $this->getEM()->persist($organization);
    }

    private function organizationNameChanged(OrganizationNameChanged $event): void
    {
        $this->organization($event->organizationId)->displayName = $event->displayName;
    }

    private function organizationSlugChanged(RecordedEvent $recorded, OrganizationSlugChanged $event): void
    {
        $this->organization($event->organizationId)->slug = $event->slug;

        // Reclaiming a slug the org previously freed (e.g. acme -> acme-inc -> acme):
        // release the now-stale reservation (kept for the audit trail) so the slug is live
        // again and the active-slug unique constraint stays free for a future rename away.
        $reclaimed = $this->slugReservationRepo->findActiveForOrg($event->slug, $event->organizationId);
        $reclaimed?->release($recorded->occurredAt);

        // Reserve the freed slug.
        $this->getEM()->persist(new SlugReservation(
            new Ulid(),
            $event->previousSlug,
            $event->organizationId,
            SlugReservationKind::RenamedFrom,
            $recorded->occurredAt,
        ));
    }

    private function teamCreated(RecordedEvent $recorded, TeamCreated $event): void
    {
        $this->getEM()->persist(new OrganizationTeam(
            $event->teamId,
            $this->organization($event->organizationId),
            $event->kind,
            $event->name,
            $this->user($recorded->actor->userId),
            $recorded->occurredAt,
        ));
    }

    private function teamMemberAdded(RecordedEvent $recorded, TeamMemberAdded $event): void
    {
        $this->getEM()->persist(new OrganizationTeamMember(
            $event->teamId,
            $event->userId,
            $event->organizationId,
            $this->user($recorded->actor->userId),
            $recorded->occurredAt,
        ));
    }

    private function memberJoined(RecordedEvent $recorded, MemberJoined $event): void
    {
        $this->getEM()->persist(new OrganizationMember($event->organizationId, $event->userId, $recorded->occurredAt));
    }

    private function teamDeleted(TeamDeleted $event): void
    {
        foreach ($this->organizationTeamMemberRepo->findByTeam($event->teamId) as $member) {
            $this->getEM()->remove($member);
        }

        $this->getEM()->remove($this->team($event->teamId));
    }

    /**
     * Remove the user from every team in the org; they are no longer an org member.
     */
    private function memberGone(Ulid $orgId, int $userId): void
    {
        foreach ($this->organizationTeamMemberRepo->findBy(['orgId' => $orgId, 'userId' => $userId]) as $member) {
            $this->getEM()->remove($member);
        }

        $orgMember = $this->organizationMemberRepo->findOneByOrgAndUser($orgId, $userId);
        if ($orgMember !== null) {
            $this->getEM()->remove($orgMember);
        }
    }

    private function removeMembership(Ulid $teamId, int $userId): void
    {
        $member = $this->organizationTeamMemberRepo->findOneBy(['teamId' => $teamId, 'userId' => $userId]);
        if ($member !== null) {
            $this->getEM()->remove($member);
        }
    }

    private function organization(Ulid $id): Organization
    {
        $organization = $this->organizationRepo->find($id);
        if ($organization === null) {
            throw new \LogicException('Organization read model not found for '.$id->toRfc4122().'.');
        }

        return $organization;
    }

    private function team(Ulid $teamId): OrganizationTeam
    {
        $team = $this->organizationTeamRepo->find($teamId);
        if ($team === null) {
            throw new \LogicException('Organization team read model not found for '.$teamId->toRfc4122().'.');
        }

        return $team;
    }

    private function user(?int $userId): ?User
    {
        return $userId !== null ? $this->userRepo->find($userId) : null;
    }
}
