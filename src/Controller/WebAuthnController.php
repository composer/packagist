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
use App\Security\SecondFactorProofStore;
use App\Security\TwoFactorAuthRateLimiter;
use App\Security\Voter\SecondFactorVoter;
use App\Security\Voter\WebAuthnVoter;
use App\Security\WebAuthn\ExpiredWebAuthnChallengeException;
use App\Security\WebAuthn\WebAuthnManager;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class WebAuthnController extends Controller
{
    public function __construct(
        private WebAuthnManager $webAuthn,
        private TwoFactorAuthRateLimiter $limiter,
        private TranslatorInterface $translator,
        private UriSigner $uriSigner,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/profile/security/keys', name: 'user_webauthn_manage', methods: ['GET'])]
    public function manage(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isGranted(WebAuthnVoter::VIEW)) {
            throw $this->createNotFoundException();
        }

        $returnUrl = $this->verificationReturnUrl($request);

        return $this->render('user/webauthn_manage.html.twig', [
            'user' => $user,
            'can_manage_keys' => $this->canManageKeys($user),
            'can_enroll_keys' => $this->isGranted(WebAuthnVoter::ENROLL),
            'has_return_to' => $request->query->has('return_to'),
            'totp_verification_url' => $this->generateUrl('user_verify_totp', ['return_to' => $returnUrl]),
            'webauthn_verification_url' => $this->generateUrl('user_webauthn_verify', ['return_to' => $returnUrl]),
            'backup_code' => $request->getSession()->remove('backup_code'),
        ]);
    }

    #[IsGranted(WebAuthnVoter::ENROLL)]
    #[Route('/profile/security/keys/options', name: 'user_webauthn_register_options', methods: ['POST'])]
    public function registrationOptions(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkCsrf($request);

        if (!$this->canManageKeys($user)) {
            throw $this->createAccessDeniedException();
        }

        return JsonResponse::fromJsonString($this->webAuthn->startRegistration($user), headers: ['Cache-Control' => 'no-store']);
    }

    #[IsGranted(WebAuthnVoter::ENROLL)]
    #[Route('/profile/security/keys/register', name: 'user_webauthn_register', methods: ['POST'])]
    public function register(Request $request, #[CurrentUser] User $user): Response
    {
        $this->checkCsrf($request);

        if (!$this->canManageKeys($user)) {
            throw $this->createAccessDeniedException();
        }

        try {
            $backupCode = $this->webAuthn->register($user, $request->request->getString('credential'), $request->request->getString('name'));
        } catch (BadCredentialsException|\InvalidArgumentException $e) {
            return $this->json(['error' => $this->translator->trans($e->getMessage())], 400);
        }

        if ($backupCode !== null) {
            $request->getSession()->set('backup_code', $backupCode);
        }

        return $this->json(['redirect' => $this->generateUrl('user_webauthn_manage')]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/profile/security/keys/{id}/remove', name: 'user_webauthn_remove', methods: ['GET'])]
    public function confirmRemoval(#[CurrentUser] User $user, string $id): Response
    {
        $credential = $user->findWebAuthnCredential($id);

        if ($credential === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->canManageKeys($user)) {
            $this->addFlash('info', 'Confirm your identity before removing this passkey or security key.');

            return $this->redirectToRoute('user_webauthn_manage', [
                'return_to' => $this->uriSigner->sign($this->generateUrl('user_webauthn_remove', ['id' => $id])),
            ]);
        }

        return $this->render('user/webauthn_remove.html.twig', ['user' => $user, 'credential' => $credential]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/profile/security/keys/{id}/edit', name: 'user_webauthn_edit', methods: ['POST'])]
    public function edit(Request $request, #[CurrentUser] User $user, string $id): Response
    {
        $this->checkCsrf($request);

        if (!$this->canManageKeys($user)) {
            $this->addFlash('info', 'Confirm your identity again before changing your keys. Your change has not been applied.');

            return $this->redirectToRoute('user_webauthn_manage');
        }

        $credential = $user->findWebAuthnCredential($id);

        if ($credential === null) {
            throw $this->createNotFoundException();
        }

        try {
            if ($request->request->getString('action') === 'remove') {
                $this->webAuthn->revoke($credential);
            } else {
                $this->webAuthn->rename($credential, $request->request->getString('name'));
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $this->translator->trans($e->getMessage()));
        }

        if (!$this->isGranted(WebAuthnVoter::VIEW)) {
            return $this->redirectToRoute('security_overview');
        }

        return $this->redirectToRoute('user_webauthn_manage');
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/profile/security/keys/verify/options', name: 'user_webauthn_verify_options', methods: ['POST'])]
    public function verificationOptions(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->checkCsrf($request);

        try {
            return JsonResponse::fromJsonString($this->webAuthn->startAuthentication($user), headers: ['Cache-Control' => 'no-store']);
        } catch (BadCredentialsException $e) {
            return $this->json(['error' => $this->translator->trans($e->getMessage())], 400);
        }
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/profile/security/keys/verify', name: 'user_webauthn_verify', methods: ['POST'])]
    public function verify(Request $request, #[CurrentUser] User $user, SecondFactorProofStore $proofs): Response
    {
        $this->checkCsrf($request);
        $returnUrl = $this->verificationReturnUrl($request);

        try {
            $this->limiter->ensureNotRateLimited($user->getUserIdentifier());
        } catch (CustomUserMessageAuthenticationException) {
            $minutes = TwoFactorAuthRateLimiter::RATE_LIMIT_DURATION;

            return $this->json(
                ['error' => $this->translator->trans('webauthn.error.too_many_attempts', ['%minutes%' => $minutes])],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => $minutes * 60],
            );
        }

        try {
            $this->webAuthn->authenticate($user, $request->request->getString('credential'));
        } catch (BadCredentialsException $e) {
            if (!$e instanceof ExpiredWebAuthnChallengeException) {
                $this->limiter->recordFailure($user->getUserIdentifier());
            }

            return $this->json(['error' => $this->translator->trans($e->getMessage())], 400);
        }

        $proofs->record($user, SecondFactorProofStore::WEBAUTHN);

        return $this->json(['redirect' => $returnUrl]);
    }

    #[Route('/2fa/webauthn/options', name: 'webauthn_login_options', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_2FA_IN_PROGRESS')]
    public function loginOptions(Request $request, TokenStorageInterface $tokens): JsonResponse
    {
        $this->checkCsrf($request);

        $token = $tokens->getToken();

        if (!$token instanceof TwoFactorTokenInterface || !$token->getUser() instanceof User
            || $token->getCurrentTwoFactorProvider() !== 'webauthn') {
            throw $this->createAccessDeniedException();
        }

        return JsonResponse::fromJsonString($this->webAuthn->startAuthentication($token->getUser()), headers: ['Cache-Control' => 'no-store']);
    }

    private function verificationReturnUrl(Request $request): string
    {
        $returnUrl = $request->query->getString('return_to');

        if ($returnUrl === '') {
            return $this->uriSigner->sign($this->generateUrl('user_webauthn_manage'));
        }

        if (!$this->uriSigner->check($returnUrl)) {
            throw $this->createAccessDeniedException();
        }

        return $returnUrl;
    }

    private function canManageKeys(User $user): bool
    {
        return !$user->hasSecondFactorAuthenticationEnabled() || $this->isGranted(SecondFactorVoter::IS_AUTHENTICATED_2FA_RECENTLY);
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('webauthn', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
