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
use App\Entity\OrganizationRepository;
use App\Entity\User;
use App\Form\Model\CreateOrganizationRequest;
use App\Form\Type\CreateOrganizationType;
use App\Form\Type\EditOrganizationType;
use App\Organization\Domain\Exception\OrganizationException;
use App\Organization\Domain\Slug;
use App\Organization\OrganizationManager;
use App\Security\Voter\OrganizationActions;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN_ORGS')]
class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationManager $organizationManager,
        private readonly OrganizationRepository $organizationRepo,
    ) {
    }

    #[Route(path: '/organizations', name: 'organization_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): Response
    {
        // Currently organizations are admin-only groundwork: every actor here holds
        // ROLE_ADMIN_ORGS and sees only the organizations they own.
        return $this->render('organization/list.html.twig', [
            'organizations' => $this->organizationRepo->findByOwner($user),
        ]);
    }

    #[Route(path: '/organizations/create', name: 'organization_create', methods: ['GET', 'POST'])]
    public function create(Request $request, #[CurrentUser] User $user): Response
    {
        // 2FA is required to create an organization / become an owner.
        if (!$user->isTotpAuthenticationEnabled()) {
            $this->addFlash('error', 'You must enable two-factor authentication before creating an organization.');

            return $this->redirectToRoute('user_2fa_configure', ['name' => $user->getUsername()]);
        }

        $createRequest = new CreateOrganizationRequest();
        $form = $this->createForm(CreateOrganizationType::class, $createRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $organization = $this->organizationManager->create(
                    $user,
                    $createRequest->slug,
                    $createRequest->displayName,
                    $request->getClientIp(),
                );

                $this->addFlash('success', sprintf('Organization "%s" created.', $organization->slug()));

                return $this->redirectToRoute('organization_show', ['organization' => $organization->slug()]);
            } catch (OrganizationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('organization/create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route(path: '/organizations/{organization}', name: 'organization_show', methods: ['GET'], requirements: ['organization' => Slug::PATTERN])]
    public function show(Organization $organization): Response
    {
        return $this->render('organization/show.html.twig', [
            'organization' => $organization,
        ]);
    }

    #[Route(path: '/organizations/{organization}/settings', name: 'organization_settings', methods: ['GET', 'POST'], requirements: ['organization' => Slug::PATTERN])]
    public function settings(Request $request, Organization $organization, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(OrganizationActions::EditDisplayInfo->value, $organization);

        // 2FA is required to manage organization settings
        if (!$user->isTotpAuthenticationEnabled()) {
            $this->addFlash('error', 'You must enable two-factor authentication to manage an organization.');

            return $this->redirectToRoute('user_2fa_configure', ['name' => $user->getUsername()]);
        }

        $editRequest = new CreateOrganizationRequest();
        $editRequest->slug = $organization->slug;
        $editRequest->displayName = $organization->displayName;

        $form = $this->createForm(EditOrganizationType::class, $editRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->organizationManager->edit(
                    $organization,
                    $user,
                    $editRequest->slug,
                    $editRequest->displayName,
                    $request->getClientIp(),
                );

                $this->addFlash('success', 'Organization settings edited.');

                return $this->redirectToRoute('organization_settings', ['organization' => $organization->slug]);
            } catch (OrganizationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('organization/settings.html.twig', [
            'organization' => $organization,
            'form' => $form->createView(),
        ]);
    }
}
