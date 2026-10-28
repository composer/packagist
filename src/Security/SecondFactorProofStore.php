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
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class SecondFactorProofStore
{
    public const string TOTP = 'totp';
    public const int RECENT_AUTHENTICATION_LIFETIME = 600;
    private const string SESSION_KEY = 'security.second_factor_authentication';

    public function __construct(private RequestStack $requests, private ClockInterface $clock)
    {
    }

    public function record(User $user, string $method): void
    {
        $request = $this->requests->getCurrentRequest();
        if (!$user->hasSecondFactorAuthenticationEnabled() || !$request?->hasSession() || $method === '' || \is_int(array_key_first([$method => true]))) {
            return;
        }
        $proofs = $this->getProofs($user) ?? new SecondFactorProofs($user->getId(), $user->getSessionBuster());
        $request->getSession()->set(self::SESSION_KEY, $proofs->withVerification($method, $this->clock->now()->getTimestamp()));
    }

    public function hasProof(User $user, ?string $method = null): bool
    {
        return $this->getProofs($user)?->latestVerificationAt($this->clock->now()->getTimestamp(), $method) !== null;
    }

    public function hasRecentProof(User $user, ?string $method = null): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $authenticatedAt = $this->getProofs($user)?->latestVerificationAt($now, $method);

        return $authenticatedAt !== null
            && $authenticatedAt > $now - self::RECENT_AUTHENTICATION_LIFETIME;
    }

    public function clear(): void
    {
        $request = $this->requests->getCurrentRequest();
        if ($request?->hasSession()) {
            $request->getSession()->remove(self::SESSION_KEY);
        }
    }

    private function getProofs(User $user): ?SecondFactorProofs
    {
        $request = $this->requests->getCurrentRequest();
        if (!$user->hasSecondFactorAuthenticationEnabled() || !$request?->hasSession()) {
            return null;
        }

        $proof = $request->getSession()->get(self::SESSION_KEY);
        if (!$proof instanceof SecondFactorProofs) {
            return null;
        }

        // Session data can survive a user switch or remain stored after the account's sessions
        // are revoked. The user ID binds proof to the account and the session buster revokes it.
        if ($proof->userId !== $user->getId() || $proof->sessionBuster !== $user->getSessionBuster()) {
            return null;
        }

        return $proof;
    }
}
