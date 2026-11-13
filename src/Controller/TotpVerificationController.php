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
use App\Form\Type\VerifyTotpType;
use App\Security\SecondFactorProofStore;
use App\Security\TwoFactorAuthRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class TotpVerificationController extends Controller
{
    public function __construct(
        private TwoFactorAuthRateLimiter $limiter,
        private SecondFactorProofStore $secondFactorProofStore,
        private UriSigner $uriSigner,
    ) {
    }

    #[Route('/profile/security/verify-totp', name: 'user_verify_totp', methods: ['GET', 'POST'])]
    public function verify(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('security_authenticator');
        }

        $returnUrl = $request->query->getString('return_to');

        if (!$this->uriSigner->check($returnUrl)) {
            throw $this->createAccessDeniedException();
        }

        // Checked before handleRequest() as validating the form already checks the code
        if ($request->isMethod('POST')) {
            try {
                $this->limiter->ensureNotRateLimited($user->getUserIdentifier());
            } catch (CustomUserMessageAuthenticationException $e) {
                throw new TooManyRequestsHttpException(TwoFactorAuthRateLimiter::RATE_LIMIT_DURATION * 60, $e->getMessage());
            }
        }

        $form = $this->createForm(VerifyTotpType::class, null, [
            'user' => $user,
            'action' => $this->generateUrl('user_verify_totp', ['return_to' => $returnUrl]),
        ])->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $this->secondFactorProofStore->record($user, SecondFactorProofStore::TOTP);

                return $this->redirect($returnUrl);
            }

            if (\count($form->get('code')->getErrors()) > 0) {
                $this->limiter->recordFailure($user->getUserIdentifier());
            }
        }

        return $this->render('user/verify_totp.html.twig', ['user' => $user, 'form' => $form, 'return_to' => $returnUrl]);
    }
}
