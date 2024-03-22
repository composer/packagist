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
use App\Form\RegistrationFormType;
use App\Security\EmailVerifier;
use App\Security\BruteForceLoginFormAuthenticator;
use App\Entity\UserRepository;
use App\Security\UserChecker;
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
    public function __construct(private EmailVerifier $emailVerifier)
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

            $user->initializeApiToken();

            $this->getEM()->persist($user);
            $this->getEM()->flush();

            // generate a signed url and email it to the user
            $this->emailVerifier->sendEmailConfirmation(
                'register_confirm_email',
                $user,
                (new TemplatedEmail())
                    ->from(new Address($mailFromEmail, $mailFromName))
                    ->to($user->getEmail())
                    ->subject('Please confirm your email')
                    ->htmlTemplate('registration/confirmation_email.html.twig')
                    ->textTemplate('registration/confirmation_email.txt.twig')
            );
            $this->addFlash('success', 'Your account has been created. Please check your email inbox to confirm the account.');

            return $this->redirectToRoute('home');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    /**
     * @param BruteForceLoginFormAuthenticator<User> $authenticator
     */
    #[Route(path: '/register/verify', name: 'register_confirm_email')]
    public function confirmEmail(Request $request, UserRepository $userRepository, UserChecker $userChecker, UserAuthenticatorInterface $userAuthenticator, BruteForceLoginFormAuthenticator $authenticator): Response
    {
        $id = $request->get('id');

        if (null === $id) {
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
}
