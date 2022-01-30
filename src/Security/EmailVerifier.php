<?php

namespace App\Security;

use App\Entity\User;
use App\Util\DoctrineTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class EmailVerifier
{
    use DoctrineTrait;

    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MailerInterface $mailer,
        private ManagerRegistry $doctrine,
    ) {
    }

    public function sendEmailConfirmation(string $verifyEmailRouteName, UserInterface $user, TemplatedEmail $email): void
    {
        if (!$user instanceof User) {
            throw new \UnexpectedValueException('Expected '.User::class.', got '.get_class($user));
        }

        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            $verifyEmailRouteName,
            (string) $user->getId(),
            $user->getEmail(),
            ['id' => $user->getId()]
        );

        $context = $email->getContext();
        $context['signedUrl'] = $signatureComponents->getSignedUrl();
        $context['expiresAtMessageKey'] = $signatureComponents->getExpirationMessageKey();
        $context['expiresAtMessageData'] = $signatureComponents->getExpirationMessageData();

        $email->context($context);

        $this->mailer->send($email);
    }

    /**
     * @throws VerifyEmailExceptionInterface
     */
    public function handleEmailConfirmation(Request $request, UserInterface $user): void
    {
        if (!$user instanceof User) {
            throw new \UnexpectedValueException('Expected '.User::class.', got '.get_class($user));
        }

        $this->verifyEmailHelper->validateEmailConfirmation($request->getUri(), (string) $user->getId(), $user->getEmail());

        if (!$user->hasRole('ROLE_SPAMMER')) {
            $user->setEnabled(true);
        }

        $this->getEM()->persist($user);
        $this->getEM()->flush();
    }
}
