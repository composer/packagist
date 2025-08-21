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
use App\Form\ResetPasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Security\BruteForceLoginFormAuthenticator;
use App\Security\Passport\Badge\ResolvedTwoFactorCodeCredentials;
use App\Security\UserChecker;
use Beelab\Recaptcha2Bundle\Recaptcha\RecaptchaVerifier;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

class ResetPasswordController extends Controller
{
    private const RESET_TTL = 86400;
    private string $mailFromName;
    private string $mailFromEmail;

    public function __construct(string $mailFromEmail, string $mailFromName)
    {
        $this->mailFromEmail = $mailFromEmail;
        $this->mailFromName = $mailFromName;
    }

    /**
     * Display & process form to request a password reset.
     */
    #[Route(path: '/reset-password', name: 'request_pwd_reset')]
    public function request(Request $request, MailerInterface $mailer, RecaptchaVerifier $recaptchaVerifier): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            return $this->processSendingPasswordResetEmail(
                $form->get('email')->getData(),
                $mailer
            );
        }

        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $form,
        ]);
    }

    /**
     * Confirmation page after a user has requested a password reset.
     */
    #[Route('/reset-password/check-email', name: 'request_pwd_check_email')]
    public function checkEmail(): Response
    {
        return $this->render('reset_password/check_email.html.twig');
    }

    /**
     * Validates and process the reset URL that the user clicked in their email.
     *
     * @param BruteForceLoginFormAuthenticator<User> $authenticator
     */
    #[Route(path: '/reset-password/reset/{token}', name: 'do_pwd_reset')]
    public function reset(Request $request, UserPasswordHasherInterface $passwordHasher, UserChecker $userChecker, UserAuthenticatorInterface $userAuthenticator, BruteForceLoginFormAuthenticator $authenticator, ?string $token = null): Response
    {
        if (null === $token) {
            throw $this->createNotFoundException('No reset password token found in the URL or in the session.');
        }

        $user = $this->getEM()->getRepository(User::class)->findOneBy(['confirmationToken' => $token]);
        if (null === $user || $user->isPasswordRequestExpired(86400)) {
            $this->addFlash('reset_password_error', 'Your password reset request has expired.');

            return $this->redirectToRoute('request_pwd_reset');
        }

        // The token is valid; allow the user to change their password.
        $form = $this->createForm(ResetPasswordFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->resetPasswordRequest();
            if (!$user->hasRole('ROLE_SPAMMER')) {
                $user->setEnabled(true);
            }

            // Encode the plain password, and set it.
            $encodedPassword = $passwordHasher->hashPassword(
                $user,
                $form->get('plainPassword')->getData()
            );

            $user->setPassword($encodedPassword);
            $this->getEM()->persist($user);
            $this->getEM()->flush();

            try {
                $userChecker->checkPreAuth($user);
            } catch (AuthenticationException $e) {
                // skip authenticating if any pre-auth check does not pass
            }

            // A user resetting the password with 2FA enabled, should automatically be marked as 2FA complete
            $badges = $user->isTotpAuthenticationEnabled() ? [new ResolvedTwoFactorCodeCredentials()] : [];
            if ($response = $userAuthenticator->authenticateUser($user, $authenticator, $request, $badges)) {
                return $response;
            }

            return $this->redirectToRoute('home');
        }

        return $this->render('reset_password/reset.html.twig', [
            'resetForm' => $form,
        ]);
    }

    private function processSendingPasswordResetEmail(string $userEmail, MailerInterface $mailer): RedirectResponse
    {
        $user = $this->getEM()->getRepository(User::class)->findOneByUsernameOrEmail($userEmail);

        // Do not reveal whether a user account was found or not.
        if (!$user) {
            return $this->redirectToRoute('request_pwd_check_email');
        }

        if (null === $user->getConfirmationToken() || $user->isPasswordRequestExpired(self::RESET_TTL)) {
            // only regenerate a new token once every 24h or as needed
            $user->initializeConfirmationToken();
            $user->setPasswordRequestedAt(new \DateTimeImmutable());
            $this->getEM()->flush();
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFromEmail, $this->mailFromName))
            ->to($user->getEmail())
            ->subject('Your password reset request')
            ->textTemplate('reset_password/email.txt.twig')
            ->context([
                'token' => $user->getConfirmationToken(),
            ])
        ;

        $mailer->send($email);

        return $this->redirectToRoute('request_pwd_check_email');
    }
}
