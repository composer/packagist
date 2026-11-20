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
use App\Entity\UserRepository;
use App\Event\SecondFactorRemovedEvent;
use App\Event\SecondFactorsResetEvent;
use App\Service\Locker;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Backup\BackupCodeManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Attribute\AsTwigFunction;

class SecondFactorManager implements BackupCodeManagerInterface
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private EntityManagerInterface $em,
        private RequestStack $requestStack,
        private UserNotifier $userNotifier,
        private Locker $locker,
        private UserRepository $users,
        private RoleHierarchyInterface $roles,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public function withAccountLock(User $user, \Closure $operation): mixed
    {
        $userId = $user->getId();

        if (!$this->locker->lockAccountSecurity($userId, 5)) {
            throw new ServiceUnavailableHttpException(1, 'Account security settings are being updated. Please try again.');
        }

        try {
            // A concurrent factor change can invalidate the authorization checked before the lock
            if (!$this->users->refreshIfSessionIsCurrent($user)) {
                throw new BadCredentialsException('Account security settings have changed. Please sign in again.');
            }

            return $operation();
        } finally {
            $this->locker->unlockAccountSecurity($userId);
        }
    }

    #[AsTwigFunction(name: 'can_use_backup_codes')]
    public function canUseBackupCodes(User $user): bool
    {
        // The two-factor challenge token does not carry the account's admin roles
        return !\in_array('ROLE_ADMIN', $this->roles->getReachableRoleNames($user->getRoles()), true);
    }

    public function generateBackupCode(User $user): ?string
    {
        if (!$this->canUseBackupCodes($user)) {
            return null;
        }

        do {
            $code = bin2hex(random_bytes(4));
        } while ($user->isBackupCode($code));

        $user->setBackupCode($code);

        return $code;
    }

    public function regenerateBackupCode(User $user): string
    {
        $code = $this->withAccountLock($user, function () use ($user): string {
            $code = $user->hasSecondFactorAuthenticationEnabled() ? $this->generateBackupCode($user) : null;

            if ($code === null) {
                throw new BadCredentialsException('Account security settings have changed. Please sign in again.');
            }

            $user->invalidateAllSessions();
            $this->em->flush();

            return $code;
        });

        $this->userNotifier->notifyChange(
            $user->getEmail(),
            reason: 'A new two-factor backup code was generated for your account.',
            username: $user->getUsername(),
        );

        return $code;
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
        return $user instanceof User && $this->canUseBackupCodes($user) && $user->isBackupCode($code);
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
        $user->invalidateAllSessions();
    }

    public function invalidateBackupCode(object $user, string $code): void
    {
        if (!$user instanceof User) {
            return;
        }

        try {
            $factorsReset = $this->withAccountLock($user, function () use ($user, $code): bool {
                if (!$this->isBackupCode($user, $code)) {
                    throw new BadCredentialsException('Invalid backup code.');
                }

                return $this->em->wrapInTransaction(function () use ($user): bool {
                    if ($user->hasVerifiedHardwareKey()) {
                        $user->invalidateAllBackupCodes();
                        $user->invalidateAllSessions();

                        return false;
                    }

                    $this->stageSecondFactorReset($user, $user, 'Backup code used');

                    return true;
                });
            });
        } catch (ServiceUnavailableHttpException $e) {
            throw new CustomUserMessageAuthenticationException($e->getMessage(), previous: $e);
        }

        $this->userNotifier->notifyChange(
            $user->getEmail(),
            reason: $factorsReset ? 'A backup code was used to sign in and all configured second factors were disabled.' : 'A backup code was used to sign in. Your verified hardware keys and other second factors remain enrolled.',
            subject: $factorsReset ? 'Two-factor authentication disabled after backup code use on Packagist.org' : 'Backup code used on Packagist.org',
            username: $user->getUsername(),
        );

        $session = $this->requestStack->getCurrentRequest()?->getSession();

        if (null === $session) {
            return;
        }

        \assert($session instanceof FlashBagAwareSessionInterface);
        $session->getFlashBag()->add('warning', $factorsReset
            ? 'Use of your backup code has disabled two-factor authentication for your account. Please consider re-enabling it for your security.'
            : 'Your one-time backup code has been used. You can generate a new code in your security settings after confirming with an enrolled second factor. If you have lost all your verified hardware keys, contact support for a reset.');
    }
}
