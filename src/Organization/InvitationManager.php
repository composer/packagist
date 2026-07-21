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

namespace App\Organization;

use App\Entity\Organization as OrganizationReadModel;
use App\Entity\OrganizationInvitation;
use App\Entity\OrganizationInvitationRepository;
use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\OrganizationTeamRepository;
use App\Entity\User;
use App\Organization\Domain\Email;
use App\Organization\Domain\Exception\DuplicatePendingInvitationException;
use App\Organization\Domain\Exception\NoTeamSpecifiedException;
use App\Organization\Domain\Exception\TeamNotFoundException;
use App\Organization\Domain\Invitation;
use App\Organization\Domain\Organization;
use App\Organization\EventStore\Actor;
use App\Organization\EventStore\EventStore;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Application service for membership invitations. Follows the reconstitute → command → append pattern of
 * the other org managers: external facts (org state, team existence, duplicate detection, the invitee's
 * 2FA) are resolved here, the aggregates enforce the domain invariants, and acceptance appends the
 * invitation and org streams together in one transaction via {@see EventStore::appendAll()}.
 */
final class InvitationManager
{
    /** Days an organization invitation link stays valid before it lazily expires. */
    private const int INVITATION_EXPIRY_DAYS = 7;

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly OrganizationInvitationRepository $invitations,
        private readonly OrganizationTeamRepository $teams,
        private readonly OrganizationTeamMemberRepository $teamMembers,
        private readonly InvitationTokenGenerator $tokens,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ClockInterface $clock,
        private readonly Security $security,
        private readonly string $mailFromEmail,
        private readonly string $mailFromName,
    ) {
    }

    /**
     * Invite an email address to one or more teams: create the invitation, store the link token hash and
     * send the email.
     *
     * @param list<Ulid> $teamIds
     *
     * @throws DuplicatePendingInvitationException
     * @throws TeamNotFoundException
     * @throws \App\Organization\Domain\Exception\NoTeamSpecifiedException
     */
    public function invite(OrganizationReadModel $organization, User $actor, string $email, array $teamIds, ?string $ip): void
    {
        $emailVo = new Email($email);
        $now = $this->clock->now();

        // Rate limiting (per-org pending cap, per-user/24h, per-IP) is intentionally not enforced yet;
        // the concrete limits are an open question. See .task/open-questions.md. This is the seam.

        if ($this->invitations->findLiveForEmail($organization->id, $emailVo->canonical, $now) !== null) {
            throw new DuplicatePendingInvitationException(sprintf('There is already a pending invitation for "%s".', $emailVo->value));
        }

        $this->assertTeamsBelongToOrg($organization, $teamIds);

        $token = $this->tokens->generate();
        $expiresAt = $now->add(new \DateInterval('P'.self::INVITATION_EXPIRY_DAYS.'D'));

        $invitation = Invitation::send(new Ulid(), $organization->id, $emailVo, $teamIds, $token['hash'], $expiresAt);
        $this->eventStore->append($invitation, $this->ownerActor($actor, $organization), $ip);

        $this->sendInvitationEmail($organization, $emailVo, $invitation->id, $token['raw'], $expiresAt);
    }

    /**
     * Bump the expiry and re-send the link, invalidating the previous one.
     *
     * @throws \App\Organization\Domain\Exception\NoPendingInvitationException
     */
    public function resend(OrganizationReadModel $organization, User $actor, OrganizationInvitation $invitation, ?string $ip): void
    {
        $now = $this->clock->now();
        $token = $this->tokens->generate();
        $newExpiresAt = $now->add(new \DateInterval('P'.self::INVITATION_EXPIRY_DAYS.'D'));

        $aggregate = $this->reconstituteInvitation($invitation->id);
        $aggregate->resend($token['hash'], $newExpiresAt, $now);
        $this->eventStore->append($aggregate, $this->ownerActor($actor, $organization), $ip);

        $emailVo = new Email($invitation->email);
        $this->sendInvitationEmail($organization, $emailVo, $invitation->id, $token['raw'], $newExpiresAt);
    }

    /**
     * Cancel a pending invitation. A no-op if it is already resolved.
     */
    public function revoke(OrganizationReadModel $organization, User $actor, OrganizationInvitation $invitation, ?string $ip): void
    {
        $aggregate = $this->reconstituteInvitation($invitation->id);
        $aggregate->revoke($this->clock->now());
        $this->eventStore->append($aggregate, $this->ownerActor($actor, $organization), $ip);
    }

    /**
     * The invitee accepts. Resolves the still-existing target teams, enforces the invitation invariants
     * (email match, 2FA for owners) and adds the org membership, all in one transaction. The caller has
     * already validated the link token.
     *
     * @throws \App\Organization\Domain\Exception\EmailMismatchException
     * @throws \App\Organization\Domain\Exception\PolicyNotMetException
     * @throws \App\Organization\Domain\Exception\InvitationNotPendingException
     * @throws \App\Organization\Domain\Exception\NoPendingInvitationException
     * @throws TeamNotFoundException
     */
    public function accept(OrganizationInvitation $invitation, User $user, ?string $ip): void
    {
        $organization = $this->reconstituteOrganization($invitation->orgId);

        $aggregate = $this->reconstituteInvitation($invitation->id);
        $now = $this->clock->now();

        $acceptedTeamIds = $organization->existingTeamsAmong($aggregate->teamIds());
        $ownersAmongTeams = $this->containsOwnersTeam($organization, $acceptedTeamIds);

        $aggregate->accept(
            $user->getId(),
            $user->getEmailCanonical(),
            $acceptedTeamIds,
            $ownersAmongTeams,
            $user->isTotpAuthenticationEnabled(),
            $now,
        );
        $organization->joinViaInvitation($user->getId(), $acceptedTeamIds, $invitation->id);

        $this->eventStore->appendAll([$aggregate, $organization], Actor::member($user), $ip);
    }

    /**
     * The invitee declines. A no-op if already resolved. The caller has already validated the link token.
     *
     * @throws \App\Organization\Domain\Exception\EmailMismatchException
     */
    public function decline(OrganizationInvitation $invitation, User $user, ?string $ip): void
    {
        $aggregate = $this->reconstituteInvitation($invitation->id);
        $aggregate->decline($user->getEmailCanonical(), $this->clock->now());
        $this->eventStore->append($aggregate, Actor::member($user), $ip);
    }

    /**
     * Lazily flip a due invitation to `expired`, recorded with a system actor. Called by the link handler
     * when a still-pending row is past its expiry. A no-op if it is not actually due.
     */
    public function expireIfDue(OrganizationInvitation $invitation, ?string $ip): void
    {
        $aggregate = $this->reconstituteInvitation($invitation->id);
        $aggregate->markExpired($this->clock->now());
        $this->eventStore->append($aggregate, Actor::system(), $ip);
    }

    private function reconstituteInvitation(Ulid $invitationId): Invitation
    {
        return Invitation::reconstitute($invitationId, $this->eventStore->loadHistory($invitationId));
    }

    private function reconstituteOrganization(Ulid $orgId): Organization
    {
        return Organization::reconstitute($orgId, $this->eventStore->loadHistory($orgId));
    }

    /**
     * @param list<Ulid> $teamIds
     */
    private function containsOwnersTeam(Organization $organization, array $teamIds): bool
    {
        foreach ($teamIds as $teamId) {
            if ($teamId->equals($organization->ownersTeamId())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Ulid> $teamIds
     *
     * @throws \App\Organization\Domain\Exception\NoTeamSpecifiedException
     * @throws TeamNotFoundException
     */
    private function assertTeamsBelongToOrg(OrganizationReadModel $organization, array $teamIds): void
    {
        if ($teamIds === []) {
            throw new NoTeamSpecifiedException('Select at least one team to invite to.');
        }

        $existing = [];
        foreach ($this->teams->findByOrg($organization->id) as $team) {
            $existing[$team->teamId->toRfc4122()] = true;
        }

        foreach ($teamIds as $teamId) {
            if (!isset($existing[$teamId->toRfc4122()])) {
                throw new TeamNotFoundException('One of the selected teams does not exist in this organization.');
            }
        }
    }

    /**
     * An owner acts as a plain member; a platform moderator who is not an owner acts as `packagist-admin`.
     * Mirrors {@see OrganizationMembershipManager::actorFor()}.
     */
    private function ownerActor(User $actor, OrganizationReadModel $organization): Actor
    {
        if ($this->teamMembers->isOwner($organization->ownersTeamId, $actor->getId())) {
            return Actor::member($actor);
        }

        if ($this->security->isGranted('ROLE_ADMIN_ORGS')) {
            return Actor::packagistAdmin($actor);
        }

        return Actor::member($actor);
    }

    private function sendInvitationEmail(OrganizationReadModel $organization, Email $email, Ulid $invitationId, string $rawToken, \DateTimeImmutable $expiresAt): void
    {
        $acceptUrl = $this->urlGenerator->generate(
            'organization_invitation_show',
            ['organization' => $organization->slug, 'invite' => $invitationId->toBase32(), 'token' => $rawToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $message = new TemplatedEmail()
            ->from(new Address($this->mailFromEmail, $this->mailFromName))
            ->to($email->value)
            ->subject(sprintf('You have been invited to the "%s" organization on Packagist', $organization->displayName))
            ->textTemplate('email/organization_invitation.txt.twig')
            ->context([
                'organizationName' => $organization->displayName,
                'acceptUrl' => $acceptUrl,
                'expiresAt' => $expiresAt,
            ]);

        $this->mailer->send($message);
    }
}
