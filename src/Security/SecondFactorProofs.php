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

final readonly class SecondFactorProofs
{
    /** @param array<string, int> $authenticationTimes */
    public function __construct(
        public int $userId,
        public int $sessionBuster,
        private array $authenticationTimes = [],
    ) {
    }

    public function withVerification(string $method, int $authenticatedAt): self
    {
        $times = $this->verificationTimesAt($authenticatedAt);
        $times[$method] = $authenticatedAt;

        return new self($this->userId, $this->sessionBuster, $times);
    }

    public function hasVerificationAfter(?int $after, int $now, ?string $method = null): bool
    {
        $times = $this->verificationTimesAt($now);
        if ($method !== null) {
            $times = isset($times[$method]) ? [$times[$method]] : [];
        }

        foreach ($times as $authenticatedAt) {
            if ($after === null || $authenticatedAt > $after) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, int> */
    private function verificationTimesAt(int $now): array
    {
        return array_filter($this->authenticationTimes, static fn (int $authenticatedAt): bool => $authenticatedAt <= $now);
    }
}
