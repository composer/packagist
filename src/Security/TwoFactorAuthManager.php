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
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Twig\Environment;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * @author Colin O'Dell <colinodell@gmail.com>
 */
class TwoFactorAuthManager implements BackupCodeManagerInterface
{
    protected $doctrine;
    protected MailerInterface $mailer;
    protected $twig;
    protected $logger;
    protected $flashBag;
    protected $options;

    public function __construct(ManagerRegistry $doctrine, MailerInterface $mailer, Environment $twig, LoggerInterface $logger, FlashBagInterface $flashBag, array $options)
    {
        $this->doctrine = $doctrine;
        $this->mailer = $mailer;
        $this->twig = $twig;
        $this->logger = $logger;
        $this->flashBag = $flashBag;
        $this->options = $options;
    }

    /**
     * Enable two-factor auth on the given user account and send confirmation email.
     *
     * @param User   $user
     * @param string $secret
     */
    public function enableTwoFactorAuth(User $user, string $secret)
    {
        $user->setTotpSecret($secret);
        $this->doctrine->getManager()->flush();

        $body = $this->twig->render('email/two_factor_enabled.txt.twig', array(
            'username' => $user->getUsername(),
        ));

        $message = (new Email())
            ->subject('[Packagist] Two-factor authentication enabled')
            ->from(new Address($this->options['from'], $this->options['fromName']))
            ->to($user->getEmail())
            ->text($body)
        ;

        try {
            $this->mailer->send($message);
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            $this->logger->error('['.get_class($e).'] '.$e->getMessage());
        }
    }

    /**
     * Disable two-factor auth on the given user account and send confirmation email.
     *
     * @param User   $user
     * @param string $reason
     */
    public function disableTwoFactorAuth(User $user, string $reason)
    {
        $user->setTotpSecret(null);
        $user->invalidateAllBackupCodes();
        $this->doctrine->getManager()->flush();

        $body = $this->twig->render('email/two_factor_disabled.txt.twig', array(
            'username' => $user->getUsername(),
            'reason' => $reason,
        ));

        $message = (new Email())
            ->subject('[Packagist] Two-factor authentication disabled')
            ->from(new Address($this->options['from'], $this->options['fromName']))
            ->to($user->getEmail())
            ->text($body)
        ;

        try {
            $this->mailer->send($message);
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
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
    public function isBackupCode($user, string $code): bool
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
    public function invalidateBackupCode($user, string $code): void
    {
        if ($user instanceof BackupCodeInterface) {
            $this->disableTwoFactorAuth($user, 'Backup code used');
            $this->flashBag->add('warning', 'Use of your backup code has disabled two-factor authentication for your account. Please consider re-enabling it for your security.');
        }
    }
}