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

use App\Attribute\VarName;
use App\Entity\Organization;
use App\Entity\OrganizationRepository;
use App\Entity\User;
use App\Form\Model\CreateOrganizationRequest;
use App\Form\Type\CreateOrganizationType;
use App\Organization\Domain\Exception\OrganizationException;
use App\Organization\OrganizationManager;
use App\Security\Voter\OrganizationActions;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ORGANIZATIONS')]
class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationManager $organizationManager,
        private readonly OrganizationRepository $organizations,
    ) {
    }

    #[Route(path: '/organizations', name: 'organization_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            $organizations = $this->organizations->findAllOrdered();
        } else {
            $organizations = $this->organizations->findByOwner($user);
        }

        return $this->render('organization/list.html.twig', [
            'organizations' => $organizations
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

    #[Route(path: '/organizations/{organization}', name: 'organization_show', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function show(Organization $organization): Response
    {
        return $this->render('organization/show.html.twig', [
            'organization' => $organization,
        ]);
    }
}
