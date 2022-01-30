<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Security;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Backup\BackupCodeManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Twig\Environment;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * @author Colin O'Dell <colinodell@gmail.com>
 */
class TwoFactorAuthManager implements BackupCodeManagerInterface
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private MailerInterface $mailer,
        private Environment $twig,
        private LoggerInterface $logger,
        private RequestStack $requestStack,
        /** @var array{from: string, fromName: string} */
        private array $options
    ) {
    }

    /**
     * Enable two-factor auth on the given user account and send confirmation email.
     */
    public function enableTwoFactorAuth(User $user, string $secret): void
    {
        $user->setTotpSecret($secret);
        $this->doctrine->getManager()->flush();

        $body = $this->twig->render('email/two_factor_enabled.txt.twig', [
            'username' => $user->getUsername(),
        ]);

        $message = (new Email())
            ->subject('[Packagist] Two-factor authentication enabled')
            ->from(new Address($this->options['from'], $this->options['fromName']))
            ->to($user->getEmail())
            ->text($body)
        ;

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('['.get_class($e).'] '.$e->getMessage());
        }
    }

    /**
     * Disable two-factor auth on the given user account and send confirmation email.
     *
     * @param User   $user
     * @param string $reason
     */
    public function disableTwoFactorAuth(User $user, string $reason): void
    {
        $user->setTotpSecret(null);
        $user->invalidateAllBackupCodes();
        $this->doctrine->getManager()->flush();

        $body = $this->twig->render('email/two_factor_disabled.txt.twig', [
            'username' => $user->getUsername(),
            'reason' => $reason,
        ]);

        $message = (new Email())
            ->subject('[Packagist] Two-factor authentication disabled')
            ->from(new Address($this->options['from'], $this->options['fromName']))
            ->to($user->getEmail())
            ->text($body)
        ;

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('['.get_class($e).'] '.$e->getMessage());
        }
    }

    /**
     * Generate a new backup code and save it on the given user account.
     *
     * @param User $user
     *
     * @return string
     */
    public function generateAndSaveNewBackupCode(User $user): string
    {
        $code = bin2hex(random_bytes(4));
        $user->setBackupCode($code);

        $this->doctrine->getManager()->flush();

        return $code;
    }

    /**
     * Check if the code is a valid backup code of the user.
     *
     * @param User   $user
     * @param string $code
     *
     * @return bool
     */
    public function isBackupCode(object $user, string $code): bool
    {
        if ($user instanceof BackupCodeInterface) {
            return $user->isBackupCode($code);
        }

        return false;
    }

    /**
     * Invalidate a backup code from a user.
     *
     * This should only be called after the backup code has been confirmed and consumed.
     *
     * @param User   $user
     * @param string $code
     */
    public function invalidateBackupCode(object $user, string $code): void
    {
        if (!$user instanceof BackupCodeInterface) {
            return;
        }

        $this->disableTwoFactorAuth($user, 'Backup code used');
        $session = $this->requestStack->getCurrentRequest()?->getSession();

        if (null === $session) {
            return;
        }

        $session->getFlashBag()->add('warning', 'Use of your backup code has disabled two-factor authentication for your account. Please consider re-enabling it for your security.');
    }
}
