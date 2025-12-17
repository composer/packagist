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

use App\Entity\AuditRecord;
use App\Entity\User;
use App\Entity\UserRepository;
use App\Form\RegistrationFormType;
use App\Form\UpdateEmailFormType;
use App\Security\BruteForceLoginFormAuthenticator;
use App\Security\EmailVerifier;
use App\Security\UserChecker;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends Controller
{
    public function __construct(private EmailVerifier $emailVerifier, private string $internalSecret, private ClockInterface $clock)
    {
    }

    #[Route(path: '/register/', name: 'register')]
    public function register(Request $request, UserPasswordHasherInterface $passwordHasher, string $mailFromEmail, string $mailFromName): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('home');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // encode the plain password
            $user->setPassword(
                $passwordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            $this->getEM()->persist($user);
            $this->getEM()->flush();

            // generate a signed url and email it to the user
            $this->emailVerifier->sendEmailConfirmation(
                'register_confirm_email',
                $user,
                new TemplatedEmail()
                    ->from(new Address($mailFromEmail, $mailFromName))
                    ->to($user->getEmail())
                    ->subject('Please confirm your email')
                    ->htmlTemplate('registration/confirmation_email.html.twig')
                    ->textTemplate('registration/confirmation_email.txt.twig')
            );

            // Redirect to confirmation page with signed token
            $token = $this->generateRegistrationToken($user);

            return $this->redirectToRoute('register_check_email', ['token' => $token]);
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route(path: '/register/check-email/{token}', name: 'register_check_email')]
    public function checkEmailConfirmation(string $token, UserRepository $userRepository): Response
    {
        $result = $this->validateRegistrationToken($token, $userRepository);

        if ($result === null) {
            $this->addFlash('error', 'This link is invalid or has expired. Please register again.');
            return $this->redirectToRoute('register');
        }

        $form = $this->createForm(UpdateEmailFormType::class, $result['user']);

        return $this->render('registration/check_email.html.twig', [
            'email' => $result['email'],
            'token' => $token,
            'form' => $form,
        ]);
    }

    #[Route(path: '/register/resend/{token}', name: 'register_resend', methods: ['POST'])]
    public function resendConfirmation(string $token, Request $request, UserRepository $userRepository, string $mailFromEmail, string $mailFromName): Response
    {
        $result = $this->validateRegistrationToken($token, $userRepository);

        if ($result === null) {
            $this->addFlash('error', 'This link is invalid or has expired. Please register again.');
            return $this->redirectToRoute('register');
        }

        $user = $result['user'];
        $oldEmail = $user->getEmail();

        $form = $this->createForm(UpdateEmailFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($oldEmail !== $user->getEmail()) {
                $this->getEM()->persist(AuditRecord::emailChanged($user, $user, $oldEmail));
                $this->getEM()->flush();
            }

            // Resend confirmation email
            $this->emailVerifier->sendEmailConfirmation(
                'register_confirm_email',
                $user,
                new TemplatedEmail()
                    ->from(new Address($mailFromEmail, $mailFromName))
                    ->to($user->getEmail())
                    ->subject('Please confirm your email')
                    ->htmlTemplate('registration/confirmation_email.html.twig')
                    ->textTemplate('registration/confirmation_email.txt.twig')
            );

            // Generate new token with updated email
            $newToken = $this->generateRegistrationToken($user);

            $this->addFlash('success', 'Confirmation email has been sent to ' . $user->getEmail());

            return $this->redirectToRoute('register_check_email', ['token' => $newToken]);
        }

        // If form is invalid, redisplay the page with errors
        return $this->render('registration/check_email.html.twig', [
            'email' => $user->getEmail(),
            'token' => $token,
            'form' => $form,
        ]);
    }

    /**
     * @param BruteForceLoginFormAuthenticator<User> $authenticator
     */
    #[Route(path: '/register/verify', name: 'register_confirm_email')]
    public function confirmEmail(Request $request, UserRepository $userRepository, UserChecker $userChecker, UserAuthenticatorInterface $userAuthenticator, BruteForceLoginFormAuthenticator $authenticator): Response
    {
        $id = $request->query->getInt('id');

        if (0 === $id) {
            return $this->redirectToRoute('register');
        }

        $user = $userRepository->find($id);

        if (null === $user) {
            return $this->redirectToRoute('register');
        }

        // validate email confirmation link, sets User::isVerified=true and persists
        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('verify_email_error', $exception->getReason());

            return $this->redirectToRoute('register');
        }

        $this->addFlash('success', 'Your email address has been verified. You are now logged in.');

        try {
            $userChecker->checkPreAuth($user);
        } catch (AuthenticationException $e) {
            // skip authenticating if any pre-auth check does not pass
        }

        if ($response = $userAuthenticator->authenticateUser($user, $authenticator, $request)) {
            return $response;
        }

        return $this->redirectToRoute('home');
    }

    private function generateRegistrationToken(User $user): string
    {
        $timestamp = $this->clock->now()->getTimestamp();
        $data = $user->getId() . '|' . $user->getEmail() . '|' . $timestamp;
        $signature = hash_hmac('sha256', $data, $this->internalSecret);

        return base64_encode($data . '|' . $signature);
    }

    /**
     * @return array{user: User, email: string}|null
     */
    private function validateRegistrationToken(string $token, UserRepository $userRepository): ?array
    {
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return null;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 4) {
            return null;
        }

        [$userId, $email, $timestamp, $providedSignature] = $parts;

        // Check expiration (10 minutes = 600 seconds)
        $now = $this->clock->now()->getTimestamp();
        if ($now - (int) $timestamp > 600) {
            return null;
        }

        // Verify signature
        $data = $userId . '|' . $email . '|' . $timestamp;
        $expectedSignature = hash_hmac('sha256', $data, $this->internalSecret);
        if (!hash_equals($expectedSignature, $providedSignature)) {
            return null;
        }

        // Load user
        $user = $userRepository->find((int) $userId);
        if ($user === null) {
            return null;
        }

        // Check if user is already enabled
        if ($user->isEnabled()) {
            return null;
        }

        return ['user' => $user, 'email' => $email];
    }
}
