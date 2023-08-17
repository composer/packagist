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

use App\Entity\User;
use App\Entity\WebauthnCredentialRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Webauthn\PublicKeyCredentialUserEntity;

class ManageAuthenticatorsController extends Controller
{
    public function __construct(
        private readonly WebauthnCredentialRepository $credentialRepository
    )
    {
    }

    #[Route(path: '/profile/webauthn', name: 'manage_authenticators')]
    public function manage(): Response
    {
        $user = $this->getUser();
        !$user instanceof User || $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $authenticators = $this->credentialRepository->findAllForUserEntity(new PublicKeyCredentialUserEntity(
            $user->getUsername(),
            $user->getUserIdentifier(),
            $user->getUsername(),
        ));

        return $this->render('user/manage_authenticators.html.twig', [
            'authenticators' => $authenticators,
        ]);
    }
}
