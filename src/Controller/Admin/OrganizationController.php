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

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Entity\OrganizationRepository;
use App\Entity\UserRepository;
use App\Form\Model\AdminCreateOrganizationRequest;
use App\Form\Type\AdminCreateOrganizationType;
use App\Entity\User;
use App\Organization\Domain\Exception\OrganizationException;
use App\Organization\OrganizationManager;
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
        private readonly UserRepository $userRepo,
    ) {
    }

    #[Route(path: '/admin/organizations', name: 'admin_organization_list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('admin/organization/index.html.twig', [
            'organizations' => $this->organizationRepo->findAllOrdered(),
        ]);
    }

    #[Route(path: '/admin/organizations/create', name: 'admin_organization_create', methods: ['GET', 'POST'])]
    public function create(Request $request, #[CurrentUser] User $admin): Response
    {
        $createRequest = new AdminCreateOrganizationRequest();
        $form = $this->createForm(AdminCreateOrganizationType::class, $createRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $owner = $this->userRepo->findOneByUsernameOrEmail($createRequest->owner);

            if ($owner === null || !$owner->isTotpAuthenticationEnabled()) {
                $form->addError(new FormError('The selected owner must enable two-factor authentication before becoming an organization owner.'));
            } else {
                try {
                    $organization = $this->organizationManager->create(
                        $owner,
                        $admin,
                        $createRequest->slug,
                        $createRequest->displayName,
                        $request->getClientIp(),
                    );

                    $this->addFlash('success', sprintf('Organization "%s" created.', $organization->slug()));

                    return $this->redirectToRoute('admin_organization_list');
                } catch (OrganizationException $e) {
                    $form->addError(new FormError($e->getMessage()));
                }
            }
        }

        return $this->render('admin/organization/create.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
