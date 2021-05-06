<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Security\EmailVerifier;
use App\Security\BruteForceLoginFormAuthenticator;
use App\Entity\UserRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Guard\GuardAuthenticatorHandler;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends Controller
{
    private $emailVerifier;

    public function __construct(EmailVerifier $emailVerifier)
    {
        $this->emailVerifier = $emailVerifier;
    }

    /**
     * @Route("/register/", name="register")
     */
    public function register(Request $request, UserPasswordEncoderInterface $passwordEncoder, string $mailFromEmail, string $mailFromName): Response
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
                $passwordEncoder->encodePassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            $user->initializeApiToken();

            $this->getEM()->persist($user);
            $this->getEM()->flush();

            // generate a signed url and email it to the user
            $this->emailVerifier->sendEmailConfirmation('register_confirm_email', $user,
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
            'registrationForm' => $form->createView(),
        ]);
    }

    /**
     * @Route("/register/verify", name="register_confirm_email")
     */
    public function confirmEmail(Request $request, UserRepository $userRepository, GuardAuthenticatorHandler $guardHandler, BruteForceLoginFormAuthenticator $authenticator): Response
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

        return $guardHandler->authenticateUserAndHandleSuccess(
            $user,
            $request,
            $authenticator,
            'main' // firewall name in security.yaml
        );
    }
}
