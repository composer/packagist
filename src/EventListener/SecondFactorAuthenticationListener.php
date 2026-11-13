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

namespace App\EventListener;

use App\Entity\User;
use App\Security\Passport\Badge\ResolvedTotpCodeCredentials;
use App\Security\SecondFactorProofStore;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\Http\Authenticator\TwoFactorAuthenticator;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorCodeEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

class SecondFactorAuthenticationListener
{
    /*
     * Record the method used to complete the login challenge so later authorization can distinguish
     * TOTP from WebAuthn and check how recently the user verified that factor.
     * Scheb fires CODE_VALID before the challenge finishes, and COMPLETE also fires for backup codes.
     * We store the user ID in a request attribute at CODE_VALID so that at COMPLETE we can confirm
     * the same user verified the second factor on this request before recording proof in the session.
     */
    private const string VALIDATED_PROVIDER = '_security.validated_second_factor_provider';
    private const string VALIDATED_USER = '_security.validated_totp_user';

    public function __construct(
        private SecondFactorProofStore $secondFactorProofStore,
        private TokenStorageInterface $tokens,
        private RequestStack $requests,
    ) {
    }

    #[AsEventListener(event: TwoFactorAuthenticationEvents::CODE_VALID)]
    public function codeValidated(TwoFactorCodeEvent $event): void
    {
        $token = $this->tokens->getToken();
        $user = $event->getUser();
        if (
            $token instanceof TwoFactorTokenInterface
            && \in_array($token->getCurrentTwoFactorProvider(), [SecondFactorProofStore::TOTP, SecondFactorProofStore::WEBAUTHN], true)
            && $user instanceof User
            && $token->getUser() === $user
        ) {
            $this->requests->getCurrentRequest()?->attributes->set(self::VALIDATED_USER, $user->getId());
            $this->requests->getCurrentRequest()?->attributes->set(self::VALIDATED_PROVIDER, $token->getCurrentTwoFactorProvider());
        }
    }

    #[AsEventListener(event: TwoFactorAuthenticationEvents::COMPLETE)]
    public function completed(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        $method = $event->getRequest()->attributes->get(self::VALIDATED_PROVIDER);

        if (
            $user instanceof User
            && $user->hasSecondFactorAuthenticationEnabled()
            && \in_array($method, [SecondFactorProofStore::TOTP, SecondFactorProofStore::WEBAUTHN], true)
            && $event->getRequest()->attributes->get(self::VALIDATED_USER) === $user->getId()
        ) {
            $this->secondFactorProofStore->record($user, $method);
        }
    }

    #[AsEventListener(dispatcher: 'security.event_dispatcher.main')]
    public function loggedIn(LoginSuccessEvent $event): void
    {
        if ($event->getAuthenticator() instanceof TwoFactorAuthenticator) {
            return;
        }
        $this->secondFactorProofStore->clear();
        $user = $event->getUser();
        if (
            $user instanceof User
            && $user->isTotpAuthenticationEnabled()
            && $event->getPassport()->hasBadge(ResolvedTotpCodeCredentials::class)
        ) {
            $this->secondFactorProofStore->record($user, SecondFactorProofStore::TOTP);
        }
    }

    #[AsEventListener(dispatcher: 'security.event_dispatcher.main')]
    public function loggedOut(LogoutEvent $event): void
    {
        $this->secondFactorProofStore->clear();
    }
}
