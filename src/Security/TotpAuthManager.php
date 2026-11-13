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
use App\Event\SecondFactorsResetEvent;
use App\Event\SecondFactorRemovedEvent;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @author Colin O'Dell <colinodell@gmail.com>
 */
class TotpAuthManager
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private UserNotifier $userNotifier,
        private AuditRecordRepository $auditRecordRepository,
        private EventDispatcherInterface $eventDispatcher,
        private BackupCodeManager $backupCodes,
    ) {
    }

    /**
     * Enable TOTP authentication on the given user account and send a confirmation email.
     *
     * @param non-empty-string $secret
     */
    public function enableTotpAuthentication(User $user, User $actor, string $secret): ?string
    {
        $backupCode = $this->backupCodes->withAccountLock($user, function () use ($user, $secret): ?string {
            $code = $user->hasSecondFactorAuthenticationEnabled() ? null : $this->backupCodes->generateBackupCode($user);
            $user->setTotpSecret($secret);
            $this->doctrine->getManager()->flush();

            return $code;
        });

        $this->auditRecordRepository->insert(AuditRecord::totpAuthenticationActivated($user, $actor));

        $this->userNotifier->notifyChange(
            $user->getEmail(),
            template: 'email/totp_enabled.txt.twig',
            subject: 'Authenticator app enabled on Packagist.org',
            username: $user->getUsername()
        );

        return $backupCode;
    }

    /**
     * Disable TOTP authentication on the given user account and send confirmation email.
     */
    public function disableTotpAuthentication(User $user, User $actor, string $reason): void
    {
        $this->backupCodes->withAccountLock($user, function () use ($user): void {
            $this->removeTotpFactor($user);
            $this->doctrine->getManager()->flush();
        });

        $this->auditRecordRepository->insert(AuditRecord::totpAuthenticationDeactivated($user, $actor, $reason));
        $this->userNotifier->notifyChange(
            $user->getEmail(),
            template: 'email/totp_disabled.txt.twig',
            subject: 'Authenticator app removed on Packagist.org',
            username: $user->getUsername(),
            reason: $reason,
        );
    }

    #[AsEventListener]
    public function onSecondFactorsReset(SecondFactorsResetEvent $event): void
    {
        $user = $event->user;

        if (!$user->isTotpAuthenticationEnabled()) {
            return;
        }

        $this->removeTotpFactor($user);
        $this->doctrine->getManager()->persist(AuditRecord::totpAuthenticationDeactivated($user, $event->actor, $event->reason));
    }

    private function removeTotpFactor(User $user): void
    {
        $user->setTotpSecret(null);
        $this->eventDispatcher->dispatch(new SecondFactorRemovedEvent($user));
    }
}
