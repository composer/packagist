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
use App\Security\TwoFactorAuthRateLimiter;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class TotpVerificationController extends Controller
{
    public function __construct(
        private TotpAuthenticatorInterface $authenticator,
        private TwoFactorAuthRateLimiter $limiter,
    ) {
    }

    #[Route('/profile/security/verify-totp', name: 'user_verify_totp', methods: ['GET', 'POST'])]
    public function verify(Request $request, #[CurrentUser] User $user): Response
    {
        // There is no authenticator code to verify when TOTP is not enabled.
        if (!$user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('security_authenticator');
        }

        if (!$request->isMethod('POST')) {
            return $this->render('user/verify_totp.html.twig', ['user' => $user, 'invalid_code' => false]);
        }

        // Reject submissions that were not made with this session's verification form token.
        if (!$this->isCsrfTokenValid('verify_totp', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->limiter->ensureNotRateLimited($user->getUserIdentifier());
        } catch (CustomUserMessageAuthenticationException $e) {
            throw new TooManyRequestsHttpException(TwoFactorAuthRateLimiter::RATE_LIMIT_DURATION * 60, $e->getMessage());
        }

        // A valid TOTP code refreshes the proof required for removal, without removing the app yet.
        if ($this->authenticator->checkCode($user, $request->request->getString('code'))) {
            return $this->redirectToRoute('user_totp_disable', ['name' => $user->getUsername()]);
        }

        $this->limiter->recordFailure($user->getUserIdentifier());

        return $this->render('user/verify_totp.html.twig', ['user' => $user, 'invalid_code' => true], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
