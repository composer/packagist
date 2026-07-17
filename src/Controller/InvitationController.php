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

namespace App\Controller;

use App\Entity\OrganizationInvitation;
use App\Entity\OrganizationInvitationRepository;
use App\Entity\OrganizationInvitationTeamRepository;
use App\Entity\OrganizationRepository;
use App\Entity\User;
use App\Form\Type\InvitationConfirmType;
use App\Organization\Domain\Exception\OrganizationException;
use App\Organization\Domain\Slug;
use App\Organization\InvitationManager;
use App\Organization\InvitationTokenGenerator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/**
 * The invitee-facing side of membership invitations: the tokenized link and the accept/decline actions.
 * Requires only a logged-in user (the invitee), not org-management rights; unauthenticated visitors are
 * redirected to log in and returned here.
 */
#[IsGranted('ROLE_USER')]
class InvitationController extends Controller
{
    public function __construct(
        private readonly OrganizationInvitationRepository $organizationInvitationRepo,
        private readonly OrganizationInvitationTeamRepository $organizationInvitationTeamRepo,
        private readonly OrganizationRepository $organizationRepo,
        private readonly InvitationManager $invitationManager,
        private readonly InvitationTokenGenerator $tokens,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/organizations/{organization}/invitations/{invite}/{token}', name: 'organization_invitation_show', methods: ['GET'], requirements: ['organization' => Slug::PATTERN, 'invite' => Requirement::ULID, 'token' => '[a-f0-9]{64}'])]
    public function show(string $organization, string $invite, string $token, #[CurrentUser] User $user): Response
    {
        $invitation = $this->validateLink($invite, $token);
        $this->expireIfDue($invitation);

        $org = $this->organizationRepo->find($invitation->orgId);
        $ownersTeamId = $org?->ownersTeamId;
        $targetsOwners = $ownersTeamId !== null
            && \in_array($ownersTeamId->toRfc4122(), array_map(static fn (Ulid $id): string => $id->toRfc4122(), $this->organizationInvitationTeamRepo->findTeamIds($invitation->id)), true);

        return $this->render('organization/invitation_show.html.twig', [
            'organization' => $org,
            'invitation' => $invitation,
            'slug' => $organization,
            'invite' => $invite,
            'token' => $token,
            'emailMatches' => $user->getEmailCanonical() === $invitation->emailCanonical,
            'needsTwoFactor' => $targetsOwners && !$user->isTotpAuthenticationEnabled(),
            'acceptForm' => $this->createForm(InvitationConfirmType::class)->createView(),
            'declineForm' => $this->createForm(InvitationConfirmType::class)->createView(),
        ]);
    }

    #[Route(path: '/organizations/{organization}/invitations/{invite}/{token}/accept', name: 'organization_invitation_accept', methods: ['POST'], requirements: ['organization' => Slug::PATTERN, 'invite' => Requirement::ULID, 'token' => '[a-f0-9]{64}'])]
    public function accept(Request $request, string $organization, string $invite, string $token, #[CurrentUser] User $user): Response
    {
        $invitation = $this->validateLink($invite, $token);
        $this->expireIfDue($invitation);

        $form = $this->createForm(InvitationConfirmType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->invitationManager->accept($invitation, $user, $request->getClientIp());
                $this->addFlash('success', 'You have joined the organization.');

                return $this->redirectToRoute('home');
            } catch (OrganizationException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('organization_invitation_show', ['organization' => $organization, 'invite' => $invite, 'token' => $token]);
    }

    #[Route(path: '/organizations/{organization}/invitations/{invite}/{token}/decline', name: 'organization_invitation_decline', methods: ['POST'], requirements: ['organization' => Slug::PATTERN, 'invite' => Requirement::ULID, 'token' => '[a-f0-9]{64}'])]
    public function decline(Request $request, string $organization, string $invite, string $token, #[CurrentUser] User $user): Response
    {
        $invitation = $this->validateLink($invite, $token);
        $this->expireIfDue($invitation);

        $form = $this->createForm(InvitationConfirmType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->invitationManager->decline($invitation, $user, $request->getClientIp());
                $this->addFlash('success', 'You have declined the invitation.');

                return $this->redirectToRoute('home');
            } catch (OrganizationException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('organization_invitation_show', ['organization' => $organization, 'invite' => $invite, 'token' => $token]);
    }

    /**
     * Fetch a pending invitation and verify the link token. A missing/already-resolved invitation and a
     * bad token return the same 403, so a caller without the valid token cannot tell them apart. The token
     * is checked before expiry so an expired invitation cannot be distinguished from a pending one.
     */
    private function validateLink(string $invite, string $token): OrganizationInvitation
    {
        $invitation = $this->organizationInvitationRepo->find(Ulid::fromString($invite));
        if ($invitation === null || !$invitation->isPending()) {
            throw new AccessDeniedHttpException('Invitation not found.');
        }

        if (!$this->tokens->matches($token, $invitation->tokenHash)) {
            // Internal monitoring only; not published to the transparency log.
            $this->logger->warning('Invalid organization invitation token', ['invitationId' => $invite]);

            throw new AccessDeniedHttpException('Invitation not found.');
        }

        return $invitation;
    }

    /**
     * A valid-token invitation that is past its expiry is flipped to `expired` and reported as 410 Gone to
     * its legitimate holder.
     */
    private function expireIfDue(OrganizationInvitation $invitation): void
    {
        if ($invitation->isExpired($this->clock->now())) {
            $this->invitationManager->expireIfDue($invitation, null);

            throw new GoneHttpException('This invitation has expired.');
        }
    }
}
