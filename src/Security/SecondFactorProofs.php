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

    public function latestVerificationAt(int $now, ?string $method = null): ?int
    {
        $times = $this->verificationTimesAt($now);
        if ($method !== null) {
            return $times[$method] ?? null;
        }

        return $times === [] ? null : max($times);
    }

    /** @return array<string, int> */
    private function verificationTimesAt(int $now): array
    {
        return array_filter($this->authenticationTimes, static fn (int $authenticatedAt): bool => $authenticatedAt <= $now);
    }
}
