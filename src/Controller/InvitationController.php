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

use App\Entity\Organization;
use App\Entity\OrganizationInvitation;
use App\Entity\OrganizationInvitationTeamRepository;
use App\Entity\User;
use App\Form\Type\InvitationConfirmType;
use App\Organization\Domain\Exception\OrganizationException;
use App\Organization\Domain\Slug;
use App\Organization\InvitationManager;
use App\Organization\InvitationTokenGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
        private readonly OrganizationInvitationTeamRepository $organizationInvitationTeamRepo,
        private readonly InvitationManager $invitationManager,
        private readonly InvitationTokenGenerator $tokens,
    ) {
    }

    #[Route(path: '/organizations/{organization}/invitations/{invitation}/{token}', name: 'organization_invitation_show', methods: ['GET'], requirements: ['organization' => Slug::PATTERN, 'invitation' => Requirement::ULID, 'token' => '[a-f0-9]{64}'])]
    public function show(Organization $organization, OrganizationInvitation $invitation, string $token, #[CurrentUser] User $user): Response
    {
        $this->assertToken($invitation, $token);

        $ownersTeamId = $organization->ownersTeamId;
        $targetsOwners = \in_array($ownersTeamId->toRfc4122(), array_map(static fn (Ulid $id): string => $id->toRfc4122(), $this->organizationInvitationTeamRepo->findTeamIds($invitation->id)), true);

        return $this->render('organization/invitation_show.html.twig', [
            'organization' => $organization,
            'invitation' => $invitation,
            'token' => $token,
            'emailMatches' => $user->getEmailCanonical() === $invitation->emailCanonical,
            'needsTwoFactor' => $targetsOwners && !$user->isTotpAuthenticationEnabled(),
            'acceptForm' => $this->createForm(InvitationConfirmType::class)->createView(),
            'declineForm' => $this->createForm(InvitationConfirmType::class)->createView(),
        ]);
    }

    #[Route(path: '/organizations/{organization}/invitations/{invitation}/{token}/accept', name: 'organization_invitation_accept', methods: ['POST'], requirements: ['organization' => Slug::PATTERN, 'invitation' => Requirement::ULID, 'token' => '[a-f0-9]{64}'])]
    public function accept(Request $request, Organization $organization, OrganizationInvitation $invitation, string $token, #[CurrentUser] User $user): Response
    {
        $this->assertToken($invitation, $token);

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

        return $this->redirectToRoute('organization_invitation_show', ['organization' => $organization->slug, 'invitation' => $invitation->id->toBase32(), 'token' => $token]);
    }

    #[Route(path: '/organizations/{organization}/invitations/{invitation}/{token}/decline', name: 'organization_invitation_decline', methods: ['POST'], requirements: ['organization' => Slug::PATTERN, 'invitation' => Requirement::ULID, 'token' => '[a-f0-9]{64}'])]
    public function decline(Request $request, Organization $organization, OrganizationInvitation $invitation, string $token, #[CurrentUser] User $user): Response
    {
        $this->assertToken($invitation, $token);

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

        return $this->redirectToRoute('organization_invitation_show', ['organization' => $organization->slug, 'invitation' => $invitation->id->toBase32(), 'token' => $token]);
    }

    private function assertToken(OrganizationInvitation $invitation, string $token): void
    {
        if (!$this->tokens->matches($token, $invitation->tokenHash)) {
            throw new NotFoundHttpException('Invitation not found.');
        }
    }
}
