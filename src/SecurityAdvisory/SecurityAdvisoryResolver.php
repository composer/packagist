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
     * @return array{SecurityAdvisory[], SecurityAdvisory[]} [$remaining, $withdrawn] where $withdrawn only holds advisories withdrawn by this call
     */
    public function removeWithdrawn(array $existingAdvisories, RemoteSecurityAdvisoryCollection $remoteAdvisories, string $sourceName): array
    {
        $remaining = [];
        $withdrawn = [];
        foreach ($existingAdvisories as $advisory) {
            $reportedWithdrawn = false;
            foreach ($advisory->getSourceRemoteIds($sourceName) as $sourceRemoteId) {
                if (!$remoteAdvisories->isWithdrawn($advisory->getPackageName(), $sourceRemoteId)) {
                    continue;
                }

                $reportedWithdrawn = true;
                if ($advisory->withdrawSource($sourceName, $sourceRemoteId)) {
                    $withdrawn[] = $advisory;
                }
            }

            // Only an advisory the source reported withdrawn leaves the plan; one withdrawn on an
            // earlier run stays in so a re-listing is an exact match rather than a new advisory.
            if ($reportedWithdrawn && $advisory->isWithdrawn()) {
                continue;
            }

            $remaining[] = $advisory;
        }

        return [$remaining, $withdrawn];
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
            $sourceRemoteIds = $advisory->getSourceRemoteIds($sourceName);
            if ([] === $sourceRemoteIds) {
                $unmatchedExistingAdvisories[$advisory->getPackageName()][$advisory->getPackagistAdvisoryId()] = $advisory;
            }
            foreach ($sourceRemoteIds as $sourceRemoteId) {
                $existingSourceAdvisoryMap[$advisory->getPackageName()][$sourceRemoteId] = $advisory;
            }
        }

        // Match existing advisories against the remote id
        $exactMatches = [];
        $matched = [];
        $unmatchedRemoteAdvisories = [];
        foreach ($remoteAdvisories->getPackageNames() as $packageName) {
            foreach ($remoteAdvisories->getAdvisoriesForPackageName($packageName) as $remoteAdvisory) {
                if (isset($existingSourceAdvisoryMap[$packageName][$remoteAdvisory->id])) {
                    $existingAdvisory = $existingSourceAdvisoryMap[$packageName][$remoteAdvisory->id];
                    $exactMatches[] = [$existingAdvisory, $remoteAdvisory];
                    $matched[$existingAdvisory->getPackagistAdvisoryId()] = true;
                    unset($existingSourceAdvisoryMap[$packageName][$remoteAdvisory->id]);
                } else {
                    $unmatchedRemoteAdvisories[$packageName][] = $remoteAdvisory;
                }
            }
        }

        foreach ($existingSourceAdvisoryMap as $packageName => $existingPackageRepositories) {
            foreach ($existingPackageRepositories as $existingAdvisory) {
                if (!isset($matched[$existingAdvisory->getPackagistAdvisoryId()])) {
                    $unmatchedExistingAdvisories[$packageName][$existingAdvisory->getPackagistAdvisoryId()] = $existingAdvisory;
                }
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

                        // Never resurrect a withdrawn advisory through a fuzzy match: only a packageName
                        // + CVE match may re-associate it (a remote id match was already taken by the
                        // exact pass above). A loose match could let a newly discovered issue silently
                        // inherit a withdrawn (and possibly ignored) advisory id and never be surfaced.
                        if (
                            $unmatchedAdvisory->isWithdrawn()
                            && !(null !== $remoteAdvisory->cve && $remoteAdvisory->cve === $unmatchedAdvisory->getCve())
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

        // Rows of advisories the source dropped entirely. An advisory it still lists under another
        // id (exact match on a different row, or a rename) keeps its stale rows out of here: they
        // are withdrawn in applyUnwithdrawals(), after the listed row is live, so the advisory is
        // never withdrawn for the length of a flush on the way.
        foreach ($renameMatches as [$renamedAdvisory]) {
            $matched[$renamedAdvisory->getPackagistAdvisoryId()] = true;
        }
        $unmatchedExisting = [];
        foreach ($existingSourceAdvisoryMap as $existingPackageRepositories) {
            foreach ($existingPackageRepositories as $sourceRemoteId => $existingAdvisory) {
                if (!isset($matched[$existingAdvisory->getPackagistAdvisoryId()])) {
                    $unmatchedExisting[] = [$existingAdvisory, (string) $sourceRemoteId];
                }
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
     * Flush the result before {@see self::applyMatches()} so the (packageName, activeCve) keys
     * these free can be reused in the same run.
     *
     * @return SecurityAdvisory[] the advisories withdrawn by this call
     */
    public function applyWithdrawals(SecurityAdvisoryResolvePlan $plan): array
    {
        $withdrawn = [];
        foreach ($plan->unmatchedExisting as [$advisory, $sourceRemoteId]) {
            if ($advisory->withdrawSource($plan->sourceName, $sourceRemoteId)) {
                $withdrawn[] = $advisory;
            }
        }

        return $withdrawn;
    }

    /**
     * Withdrawn advisories are updated but neither they nor their sources are revived here, that
     * happens in {@see self::applyUnwithdrawals()} once these updates are flushed.
     *
     * @return SecurityAdvisory[] freshly created advisories that still need to be persisted
     */
    public function applyMatches(SecurityAdvisoryResolvePlan $plan): array
    {
        foreach ($plan->exactMatches as [$advisory, $remoteAdvisory]) {
            $advisory->updateAdvisory($remoteAdvisory);
        }

        foreach ($plan->renameMatches as [$advisory, $remoteAdvisory]) {
            $advisory->addSource($remoteAdvisory->id, $plan->sourceName, $remoteAdvisory->severity, $remoteAdvisory->date);
            $advisory->updateAdvisory($remoteAdvisory);
        }

        $newAdvisories = [];
        foreach ($plan->newRemoteAdvisories as $remoteAdvisory) {
            $newAdvisories[] = new SecurityAdvisory($remoteAdvisory, $plan->sourceName);
        }

        return $newAdvisories;
    }

    /**
     * Reviving repopulates the activeCve generated column, so this runs last, once every CVE
     * reassignment freeing that key has been flushed. An advisory whose CVE is already held by an
     * active advisory in the working set (whichever source that one belongs to, and including the
     * ones created this run) keeps both flags set and is revived on a later run instead.
     *
     * Entity state is final by now, so the stored CVEs are what the decision is made on.
     *
     * @param SecurityAdvisory[] $newAdvisories every advisory applyMatches() created for this plan
     *
     * @return SecurityAdvisory[] the advisories a row was reinstated or withdrawn on
     */
    public function applyUnwithdrawals(SecurityAdvisoryResolvePlan $plan, array $newAdvisories): array
    {
        /** @var array<string, array<string, true>> $claimed */
        $claimed = [];
        foreach ([...$plan->existingAdvisories, ...$newAdvisories] as $advisory) {
            if (!$advisory->isWithdrawn() && null !== $cve = $advisory->getCve()) {
                $claimed[$advisory->getPackageName()][$cve] = true;
            }
        }

        $matches = [...$plan->exactMatches, ...$plan->renameMatches];

        $touched = [];
        foreach ($matches as [$advisory, $remoteAdvisory]) {
            $source = $advisory->findSecurityAdvisorySource($plan->sourceName, $remoteAdvisory->id);
            if (null === $source || !$source->isWithdrawn()) {
                continue;
            }

            $cve = $advisory->getCve();
            if ($advisory->isWithdrawn() && null !== $cve && isset($claimed[$advisory->getPackageName()][$cve])) {
                continue;
            }

            $advisory->reinstateSource($plan->sourceName, $remoteAdvisory->id);
            if (null !== $cve) {
                $claimed[$advisory->getPackageName()][$cve] = true;
            }
            $touched[$advisory->getPackagistAdvisoryId()] = $advisory;
        }

        // Rows of a listed advisory that the source stopped using, now that its listed row is live.
        /** @var array<string, array<string, true>> $listed */
        $listed = [];
        foreach ($matches as [$advisory, $remoteAdvisory]) {
            $listed[$advisory->getPackagistAdvisoryId()][$remoteAdvisory->id] = true;
        }
        foreach ($matches as [$advisory]) {
            foreach ($advisory->getSourceRemoteIds($plan->sourceName) as $sourceRemoteId) {
                if (isset($listed[$advisory->getPackagistAdvisoryId()][$sourceRemoteId])) {
                    continue;
                }
                if ($advisory->findSecurityAdvisorySource($plan->sourceName, $sourceRemoteId)?->isWithdrawn() === false) {
                    $advisory->withdrawSource($plan->sourceName, $sourceRemoteId);
                    $touched[$advisory->getPackagistAdvisoryId()] = $advisory;
                }
            }
        }

        return array_values($touched);
    }

    /**
     * Run after {@see self::applyMatches()}: every advisory that now holds a CVE another advisory in
     * the working set is giving up in the same run gives it up itself until those releases are
     * flushed, since Doctrine runs a flush's UPDATEs in identity-map order. Covers a straight
     * handover and a swap alike.
     *
     * @param array<string, string|null> $cvesBefore packagistAdvisoryId => CVE as it was before applyMatches()
     *
     * @return array<string, string> packagistAdvisoryId => CVE to hand back with {@see self::assignDeferredCves()}
     */
    public function deferContestedCves(SecurityAdvisoryResolvePlan $plan, array $cvesBefore): array
    {
        /** @var array<string, array<string, true>> $released */
        $released = [];
        foreach ($plan->existingAdvisories as $advisory) {
            $before = $cvesBefore[$advisory->getPackagistAdvisoryId()] ?? null;
            if (null !== $before && $before !== $advisory->getCve() && !$advisory->isWithdrawn()) {
                $released[$advisory->getPackageName()][$before] = true;
            }
        }

        $deferred = [];
        foreach ($plan->existingAdvisories as $advisory) {
            $cve = $advisory->getCve();
            if (null === $cve || $advisory->isWithdrawn() || ($cvesBefore[$advisory->getPackagistAdvisoryId()] ?? null) === $cve) {
                continue;
            }

            if (isset($released[$advisory->getPackageName()][$cve])) {
                $deferred[$advisory->getPackagistAdvisoryId()] = $cve;
                $advisory->deferCve();
            }
        }

        return $deferred;
    }

    /**
     * @param array<string, string> $deferred as returned by {@see self::deferContestedCves()}
     */
    public function assignDeferredCves(SecurityAdvisoryResolvePlan $plan, array $deferred): void
    {
        foreach ($plan->existingAdvisories as $advisory) {
            if (isset($deferred[$advisory->getPackagistAdvisoryId()])) {
                $advisory->assignCve($deferred[$advisory->getPackagistAdvisoryId()]);
            }
        }
    }
}
