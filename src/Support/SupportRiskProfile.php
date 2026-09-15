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

namespace App\Support;

use App\Entity\AuditRecord;

readonly class SupportRiskPackage
{
    public function __construct(
        public string $name,
        public int $downloads,
        public bool $soleMaintainer,
    ) {
    }

    public function isPopular(): bool
    {
        return $this->downloads >= SupportRiskAssessor::HIGH_VALUE_DOWNLOADS;
    }
}

/**
 * What an approved lost-2FA reset would hand over, rendered above the approve button so that
 * "most requests are just approved" stays an informed default rather than a rubber stamp.
 */
readonly class SupportRiskProfile
{
    /**
     * @param list<SupportRiskPackage> $packages          maintained packages, most downloaded first
     * @param list<AuditRecord>        $recentSecurityEvents
     * @param list<string>             $knownIps          IPs the account recently acted from
     */
    public function __construct(
        public array $packages,
        public int $totalDownloads,
        public int $organizationCount,
        public ?\DateTimeImmutable $twoFactorEnabledAt,
        public array $recentSecurityEvents,
        public bool $hasRecentCredentialChange,
        public array $knownIps,
    ) {
    }

    public function isHighRisk(): bool
    {
        return $this->hasRecentCredentialChange
            || $this->organizationCount > 0
            || $this->popularPackages() !== [];
    }

    /** @return list<SupportRiskPackage> */
    public function popularPackages(): array
    {
        return array_values(array_filter($this->packages, static fn (SupportRiskPackage $p): bool => $p->isPopular()));
    }

    /** @return list<SupportRiskPackage> */
    public function solePopularPackages(): array
    {
        return array_values(array_filter($this->popularPackages(), static fn (SupportRiskPackage $p): bool => $p->soleMaintainer));
    }

    /**
     * Whether the request came from somewhere the account has recently been seen. Shown as a plain
     * yes/no so an admin without ROLE_AUDITOR gets the signal without seeing raw IPs.
     */
    public function requestIpIsKnown(?string $requestIp): ?bool
    {
        if ($requestIp === null || $this->knownIps === []) {
            return null;
        }

        return \in_array($requestIp, $this->knownIps, true);
    }
}
