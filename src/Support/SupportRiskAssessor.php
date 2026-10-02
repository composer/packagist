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

use App\Log\AuditLogEventType;
use App\Entity\AuditRecord;
use App\Entity\AuditRecordRepository;
use App\Entity\OrganizationMemberRepository;
use App\Entity\Package;
use App\Entity\User;
use App\Model\DownloadManager;
use Predis\PredisException;

/**
 * Works out how much damage an approved lost-2FA reset could do to a given account.
 *
 * Two uses, deliberately separated: {@see isHighValue()} runs once at request time to decide the
 * cooling-off period, and its answer is frozen onto the request so a later download spike cannot
 * restart the clock. {@see assess()} runs at view time, so the admin always sees current facts.
 */
class SupportRiskAssessor
{
    /**
     * Download count above which an account is treated as high value. Same number
     * FilterListWorker uses to call a package high-download, so there is one meaning of "popular".
     */
    public const int HIGH_VALUE_DOWNLOADS = 10_000;

    public const int COOLING_OFF_HOURS = 24;

    /** A password or email change this recently means an attacker may already hold the account. */
    public const int RECENT_CREDENTIAL_CHANGE_DAYS = 7;

    /** How far back the account history shown next to the approve button reaches. */
    public const int SECURITY_EVENT_WINDOW_DAYS = 90;

    public const int MAX_SECURITY_EVENTS = 25;

    public function __construct(
        private readonly DownloadManager $downloadManager,
        private readonly AuditRecordRepository $auditRecords,
        private readonly OrganizationMemberRepository $orgMembers,
    ) {
    }

    /**
     * Whether a lost-2FA reset on this account warrants a cooling-off period.
     *
     * Organization membership counts on its own: an org owner can publish under the org's packages
     * without maintaining anything personally, so package downloads alone would let the highest-value
     * accounts through with no hold at all.
     *
     * Fails closed: if downloads cannot be read, assume high value. The cost of being wrong is a
     * 24-hour wait, not a lockout, so the safe answer is the cautious one.
     */
    public function isHighValue(User $user): bool
    {
        if ($this->organizationCount($user) > 0) {
            return true;
        }

        if ($user->getPackages()->count() === 0) {
            return false;
        }

        try {
            $downloads = $this->downloadManager->getPackagesDownloads($this->packageIds($user));
        } catch (PredisException) {
            return true;
        }

        return max([0, ...array_values($downloads)]) >= self::HIGH_VALUE_DOWNLOADS;
    }

    public function coolingOffUntil(User $user, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return $this->isHighValue($user) ? $now->modify('+'.self::COOLING_OFF_HOURS.' hours') : null;
    }

    public function assess(User $user): SupportRiskProfile
    {
        try {
            $downloads = $this->downloadManager->getPackagesDownloads($this->packageIds($user));
        } catch (PredisException) {
            $downloads = [];
        }

        $packages = [];
        foreach ($user->getPackages() as $package) {
            $packages[] = new SupportRiskPackage(
                $package->getName(),
                $downloads[$package->getId()] ?? 0,
                $package->getMaintainers()->count() === 1,
            );
        }
        usort($packages, static fn (SupportRiskPackage $a, SupportRiskPackage $b): int => $b->downloads <=> $a->downloads);

        $securityEvents = $this->recentSecurityEvents($user);

        return new SupportRiskProfile(
            packages: $packages,
            totalDownloads: array_sum(array_map(static fn (SupportRiskPackage $p): int => $p->downloads, $packages)),
            organizationCount: $this->organizationCount($user),
            twoFactorEnabledAt: $this->twoFactorEnabledAt($user),
            recentSecurityEvents: $securityEvents,
            hasRecentCredentialChange: $this->hasRecentCredentialChange($securityEvents),
            knownIps: $this->knownIps($securityEvents),
        );
    }

    /** @return array<int> */
    private function packageIds(User $user): array
    {
        return array_values(array_map(static fn (Package $p): int => $p->getId(), $user->getPackages()->toArray()));
    }

    private function organizationCount(User $user): int
    {
        return $this->orgMembers->countForUser($user->getId());
    }

    /**
     * When 2FA was last switched on. An authenticator "lost" days after it was set up is a very
     * different story from one lost after two years. Unknown if it was enabled under a previous
     * username, see {@see AuditRecordRepository::findForUser()}.
     */
    private function twoFactorEnabledAt(User $user): ?\DateTimeImmutable
    {
        $records = $this->auditRecords->findForUser($user, [AuditLogEventType::TwoFactorAuthenticationActivated], limit: 1);

        return $records[0]->datetime ?? null;
    }

    /**
     * @return list<AuditRecord>
     */
    private function recentSecurityEvents(User $user): array
    {
        return $this->auditRecords->findForUser(
            $user,
            self::securityEventTypes(),
            new \DateTimeImmutable('-'.self::SECURITY_EVENT_WINDOW_DAYS.' days'),
            self::MAX_SECURITY_EVENTS,
        );
    }

    /**
     * @param list<AuditRecord> $events
     */
    private function hasRecentCredentialChange(array $events): bool
    {
        $cutoff = new \DateTimeImmutable('-'.self::RECENT_CREDENTIAL_CHANGE_DAYS.' days');

        foreach ($events as $event) {
            $isCredentialChange = \in_array($event->type, [
                AuditLogEventType::PasswordChanged,
                AuditLogEventType::PasswordReset,
                AuditLogEventType::EmailChanged,
            ], true);

            if ($isCredentialChange && $event->datetime > $cutoff) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<AuditRecord> $events
     *
     * @return list<string> IPs the account has recently acted from, to compare against the request IP
     */
    private function knownIps(array $events): array
    {
        $ips = [];
        foreach ($events as $event) {
            if ($event->ip !== null) {
                $ips[$event->ip] = true;
            }
        }

        return array_keys($ips);
    }

    /**
     * @return list<AuditLogEventType>
     */
    private static function securityEventTypes(): array
    {
        return [
            AuditLogEventType::PasswordChanged,
            AuditLogEventType::PasswordReset,
            AuditLogEventType::PasswordResetRequested,
            AuditLogEventType::EmailChanged,
            AuditLogEventType::UsernameChanged,
            AuditLogEventType::GitHubLinkedWithUser,
            AuditLogEventType::GitHubDisconnectedFromUser,
            AuditLogEventType::TwoFactorAuthenticationActivated,
            AuditLogEventType::TwoFactorAuthenticationDeactivated,
            AuditLogEventType::UserFrozen,
            AuditLogEventType::UserUnfrozen,
        ];
    }
}
