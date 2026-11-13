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

use App\Entity\User;
use App\Event\SecondFactorsResetEvent;
use App\Event\SecondFactorRemovedEvent;
use App\Service\Locker;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Backup\BackupCodeManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class BackupCodeManager implements BackupCodeManagerInterface
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private EntityManagerInterface $em,
        private RequestStack $requestStack,
        private UserNotifier $userNotifier,
        private Locker $locker,
    ) {
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public function withAccountLock(User $user, \Closure $operation): mixed
    {
        $userId = $user->getId();
        $sessionBuster = $user->getSessionBuster();

        if (!$this->locker->lockAccountSecurity($userId, 5)) {
            throw new ServiceUnavailableHttpException(1, 'Account security settings are being updated. Please try again.');
        }

        try {
            $this->em->refresh($user);

            // A concurrent factor change can invalidate the authorization checked before the lock
            if ($user->getSessionBuster() !== $sessionBuster) {
                throw new BadCredentialsException('Account security settings have changed. Please sign in again.');
            }

            return $operation();
        } finally {
            $this->locker->unlockAccountSecurity($userId);
        }
    }

    public function generateBackupCode(User $user): string
    {
        $code = bin2hex(random_bytes(4));
        $user->setBackupCode($code);

        return $code;
    }

    public function isValidBackupCode(BackupCodeInterface $user, string $code): bool
    {
        return $user->isBackupCode($code);
    }

    private function clearIfNoSecondFactorsRemain(User $user): void
    {
        if (!$user->hasSecondFactorAuthenticationEnabled()) {
            $user->invalidateAllBackupCodes();
        }
    }

    #[AsEventListener]
    public function onSecondFactorRemoved(SecondFactorRemovedEvent $event): void
    {
        $this->clearIfNoSecondFactorsRemain($event->user);
    }

    public function isBackupCode(object $user, string $code): bool
    {
        return $user instanceof BackupCodeInterface && $this->isValidBackupCode($user, $code);
    }

    public function notifySupportReset(User $user): void
    {
        $this->userNotifier->notifyChange(
            $user->getEmail(),
            reason: 'Support approved a reset and disabled all configured second factors. If you did not request this, contact support immediately.',
            subject: 'Two-factor authentication reset on your Packagist.org account',
            username: $user->getUsername(),
        );
    }

    public function stageSecondFactorReset(User $user, User $actor, string $reason): void
    {
        $this->eventDispatcher->dispatch(new SecondFactorsResetEvent($user, $actor, $reason));

        if ($user->hasSecondFactorAuthenticationEnabled()) {
            throw new \LogicException('Account recovery must disable all second factors.');
        }

        $user->invalidateAllBackupCodes();
    }

    public function invalidateBackupCode(object $user, string $code): void
    {
        if (!$user instanceof User) {
            return;
        }

        try {
            $this->withAccountLock($user, function () use ($user, $code): void {
                if (!$this->isValidBackupCode($user, $code)) {
                    throw new BadCredentialsException('Invalid backup code.');
                }

                $this->em->wrapInTransaction(function () use ($user): void {
                    $this->stageSecondFactorReset($user, $user, 'Backup code used');
                });
            });
        } catch (ServiceUnavailableHttpException $e) {
            throw new CustomUserMessageAuthenticationException($e->getMessage(), previous: $e);
        }

        $this->userNotifier->notifyChange(
            $user->getEmail(),
            reason: 'A backup code was used to sign in and all configured second factors were disabled.',
            subject: 'Two-factor authentication disabled after backup code use on Packagist.org',
        );

        $session = $this->requestStack->getCurrentRequest()?->getSession();

        if (null === $session) {
            return;
        }

        \assert($session instanceof FlashBagAwareSessionInterface);
        $session->getFlashBag()->add('warning', 'Use of your backup code has disabled two-factor authentication for your account. Please consider re-enabling it for your security.');
    }
}
