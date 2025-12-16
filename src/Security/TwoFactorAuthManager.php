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

namespace App\Security;

use App\Entity\AuditRecord;
use App\Entity\AuditRecordRepository;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Backup\BackupCodeManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * @author Colin O'Dell <colinodell@gmail.com>
 */
class TwoFactorAuthManager implements BackupCodeManagerInterface
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private RequestStack $requestStack,
        private UserNotifier $userNotifier,
        private AuditRecordRepository $auditRecordRepository,
    ) {
    }

    /**
     * Enable two-factor auth on the given user account and send confirmation email.
     */
    public function enableTwoFactorAuth(User $user, string $secret): void
    {
        $user->setTotpSecret($secret);
        $this->doctrine->getManager()->flush();

        $this->auditRecordRepository->insert(AuditRecord::twoFactorAuthenticationActivated($user));

        $this->userNotifier->notifyChange(
            $user->getEmail(),
            template: 'email/two_factor_enabled.txt.twig',
            subject: 'Two-factor authentication enabled on Packagist.org',
            username: $user->getUsername()
        );
    }

    /**
     * Disable two-factor auth on the given user account and send confirmation email.
     */
    public function disableTwoFactorAuth(User $user, string $reason): void
    {
        $user->setTotpSecret(null);
        $user->invalidateAllBackupCodes();
        $this->doctrine->getManager()->flush();

        $this->auditRecordRepository->insert(AuditRecord::twoFactorAuthenticationDeactivated($user, $reason));

        $this->userNotifier->notifyChange(
            $user->getEmail(),
            template: 'email/two_factor_disabled.txt.twig',
            subject: 'Two-factor authentication disabled on Packagist.org',
            username: $user->getUsername(),
            reason: $reason,
        );
    }

    /**
     * Generate a new backup code and save it on the given user account.
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
     * @param User $user
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
     * @param User $user
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

        \assert($session instanceof FlashBagAwareSessionInterface);
        $session->getFlashBag()->add('warning', 'Use of your backup code has disabled two-factor authentication for your account. Please consider re-enabling it for your security.');
    }
}
