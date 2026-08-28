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

namespace App\SecurityAdvisory;

use App\Entity\SecurityAdvisory;

class SecurityAdvisoryResolver
{
    /**
     * @param SecurityAdvisory[] $existingAdvisories
     *
     * @return array{SecurityAdvisory[], SecurityAdvisory[]} [$remaining, $withdrawn]
     */
    public function removeWithdrawn(array $existingAdvisories, RemoteSecurityAdvisoryCollection $remoteAdvisories, string $sourceName): array
    {
        $remaining = [];
        $withdrawn = [];
        foreach ($existingAdvisories as $advisory) {
            $sourceRemoteId = $advisory->getSourceRemoteId($sourceName);
            if (null !== $sourceRemoteId && $remoteAdvisories->isWithdrawn($advisory->getPackageName(), $sourceRemoteId)) {
                if (!$this->hasOtherSource($advisory, $sourceName)) {
                    $advisory->withdraw();
                    $withdrawn[] = $advisory;
                    continue;
                }

                $advisory->removeSource($sourceName);
            }

            $remaining[] = $advisory;
        }

        return [$remaining, $withdrawn];
    }

    private function hasOtherSource(SecurityAdvisory $advisory, string $sourceName): bool
    {
        foreach ($advisory->getSources() as $source) {
            if ($source->getSource() !== $sourceName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether another active advisory already holds $cve for $candidate's package. When it does, a
     * withdrawn $candidate must stay withdrawn even though the source reports it as live again,
     * otherwise un-withdrawing it would violate the (packageName, activeCve) unique constraint. The
     * advisory un-withdraws on its own on a later run once the conflicting advisory is gone.
     *
     * @param SecurityAdvisory[] $existingAdvisories
     */
    private function cveClaimedByActiveAdvisory(array $existingAdvisories, SecurityAdvisory $candidate, ?string $cve): bool
    {
        if (null === $cve || !$candidate->isWithdrawn()) {
            return false;
        }

        foreach ($existingAdvisories as $advisory) {
            if ($advisory === $candidate || $advisory->isWithdrawn()) {
                continue;
            }

            if ($advisory->getPackageName() === $candidate->getPackageName() && $advisory->getCve() === $cve) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convenience wrapper that runs the full resolution in one call. Callers that persist to the
     * database should instead use {@see self::planResolve()}, {@see self::applyWithdrawals()} and
     * {@see self::applyMatches()} directly with a flush between the last two, so a reassigned CVE
     * does not collide with the advisory it was freed from within a single flush.
     *
     * @param SecurityAdvisory[] $existingAdvisories
     *
     * @return array{SecurityAdvisory[], SecurityAdvisory[]} [$newAdvisories, $withdrawnAdvisories]
     */
    public function resolve(array $existingAdvisories, RemoteSecurityAdvisoryCollection $remoteAdvisories, string $sourceName): array
    {
        $plan = $this->planResolve($existingAdvisories, $remoteAdvisories, $sourceName);
        $withdrawnAdvisories = $this->applyWithdrawals($plan);
        $newAdvisories = $this->applyMatches($plan);

        return [$newAdvisories, $withdrawnAdvisories];
    }

    /**
     * Classify every advisory without mutating anything.
     *
     * @param SecurityAdvisory[] $existingAdvisories
     */
    public function planResolve(array $existingAdvisories, RemoteSecurityAdvisoryCollection $remoteAdvisories, string $sourceName): SecurityAdvisoryResolvePlan
    {
        /** @var array<string, array<string, SecurityAdvisory>> $existingSourceAdvisoryMap */
        $existingSourceAdvisoryMap = [];
        /** @var array<string, array<string, SecurityAdvisory>> $unmatchedExistingAdvisories */
        $unmatchedExistingAdvisories = [];
        foreach ($existingAdvisories as $advisory) {
            $sourceRemoteId = $advisory->getSourceRemoteId($sourceName);
            if ($sourceRemoteId) {
                $existingSourceAdvisoryMap[$advisory->getPackageName()][$sourceRemoteId] = $advisory;
            } else {
                $unmatchedExistingAdvisories[$advisory->getPackageName()][$advisory->getPackagistAdvisoryId()] = $advisory;
            }
        }

        // Match existing advisories against the remote id
        $exactMatches = [];
        $unmatchedRemoteAdvisories = [];
        foreach ($remoteAdvisories->getPackageNames() as $packageName) {
            foreach ($remoteAdvisories->getAdvisoriesForPackageName($packageName) as $remoteAdvisory) {
                if (isset($existingSourceAdvisoryMap[$packageName][$remoteAdvisory->id])) {
                    $exactMatches[] = [$existingSourceAdvisoryMap[$packageName][$remoteAdvisory->id], $remoteAdvisory];
                    unset($existingSourceAdvisoryMap[$packageName][$remoteAdvisory->id]);
                } else {
                    $unmatchedRemoteAdvisories[$packageName][] = $remoteAdvisory;
                }
            }
        }

        foreach ($existingSourceAdvisoryMap as $packageName => $existingPackageRepositories) {
            foreach ($existingPackageRepositories as $existingAdvisory) {
                $unmatchedExistingAdvisories[$packageName][$existingAdvisory->getPackagistAdvisoryId()] = $existingAdvisory;
            }
        }

        // Try to match remaining remote advisories with remaining local advisories in case the remote id changed
        // Allow three changes e.g. filename, CVE, date on a rename
        $requiredDifferenceScore = 3;
        $renameMatches = [];
        $newRemoteAdvisories = [];
        foreach ($unmatchedRemoteAdvisories as $packageName => $packageAdvisories) {
            foreach ($packageAdvisories as $remoteAdvisory) {
                $matchedAdvisory = null;
                $matchedKey = null;
                $lowestScore = 9999;
                if (isset($unmatchedExistingAdvisories[$packageName])) {
                    foreach ($unmatchedExistingAdvisories[$packageName] as $key => $unmatchedAdvisory) {
                        $score = $unmatchedAdvisory->calculateDifferenceScore($remoteAdvisory);
                        if ($score >= $lowestScore || $score > $requiredDifferenceScore) {
                            continue;
                        }

                        // Never resurrect a withdrawn advisory through a fuzzy match: only an exact
                        // packageName + CVE or packageName + remoteId match may re-associate it. A
                        // loose match could let a newly discovered issue silently inherit a withdrawn
                        // (and possibly ignored) advisory id and never be surfaced to users.
                        if (
                            $unmatchedAdvisory->isWithdrawn()
                            && !(null !== $remoteAdvisory->cve && $remoteAdvisory->cve === $unmatchedAdvisory->getCve())
                            && $remoteAdvisory->id !== $unmatchedAdvisory->getRemoteId()
                        ) {
                            continue;
                        }

                        $matchedAdvisory = $unmatchedAdvisory;
                        $matchedKey = $key;
                        $lowestScore = $score;
                    }
                }

                // No similar existing advisories found. Store them as new advisories
                if ($matchedAdvisory === null || $matchedKey === null) {
                    $newRemoteAdvisories[] = $remoteAdvisory;
                } else {
                    $renameMatches[] = [$matchedAdvisory, $remoteAdvisory];
                    unset($unmatchedExistingAdvisories[$packageName][$matchedKey]);
                }
            }
        }

        $unmatchedExisting = [];
        foreach ($unmatchedExistingAdvisories as $packageUnmatchedAdvisories) {
            foreach ($packageUnmatchedAdvisories as $unmatchedAdvisory) {
                if (null === $unmatchedAdvisory->getSourceRemoteId($sourceName)) {
                    continue;
                }

                $unmatchedExisting[] = $unmatchedAdvisory;
            }
        }

        return new SecurityAdvisoryResolvePlan(
            $sourceName,
            $existingAdvisories,
            $exactMatches,
            $renameMatches,
            $newRemoteAdvisories,
            $unmatchedExisting,
        );
    }

    /**
     * Withdraw (or just detach the source from) the advisories the source no longer lists. Flush
     * these before {@see self::applyMatches()} so the (packageName, activeCve) keys they free can be
     * reused in the same run.
     *
     * @return SecurityAdvisory[] the advisories that were marked withdrawn
     */
    public function applyWithdrawals(SecurityAdvisoryResolvePlan $plan): array
    {
        $withdrawn = [];
        foreach ($plan->unmatchedExisting as $advisory) {
            if ($this->hasOtherSource($advisory, $plan->sourceName)) {
                $advisory->removeSource($plan->sourceName);
                continue;
            }

            $advisory->withdraw();
            $withdrawn[] = $advisory;
        }

        return $withdrawn;
    }

    /**
     * Apply the remote data to the matched advisories and build the brand new ones. Run this after
     * {@see self::applyWithdrawals()} (and a flush) so an advisory re-reported as live can reclaim a
     * CVE that a same-run withdrawal just freed.
     *
     * @return SecurityAdvisory[] freshly created advisories that still need to be persisted
     */
    public function applyMatches(SecurityAdvisoryResolvePlan $plan): array
    {
        foreach ($plan->exactMatches as [$advisory, $remoteAdvisory]) {
            $advisory->updateAdvisory($remoteAdvisory, !$this->cveClaimedByActiveAdvisory($plan->existingAdvisories, $advisory, $remoteAdvisory->cve));
        }

        foreach ($plan->renameMatches as [$advisory, $remoteAdvisory]) {
            $advisory->addSource($remoteAdvisory->id, $plan->sourceName, $remoteAdvisory->severity);
            $advisory->updateAdvisory($remoteAdvisory, !$this->cveClaimedByActiveAdvisory($plan->existingAdvisories, $advisory, $remoteAdvisory->cve));
        }

        $newAdvisories = [];
        foreach ($plan->newRemoteAdvisories as $remoteAdvisory) {
            $newAdvisories[] = new SecurityAdvisory($remoteAdvisory, $plan->sourceName);
        }

        return $newAdvisories;
    }
}
