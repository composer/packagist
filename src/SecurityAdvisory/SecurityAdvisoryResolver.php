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
     * @param SecurityAdvisory[] $existingAdvisories
     *
     * @return array{SecurityAdvisory[], SecurityAdvisory[]} [$newAdvisories, $withdrawnAdvisories]
     */
    public function resolve(array $existingAdvisories, RemoteSecurityAdvisoryCollection $remoteAdvisories, string $sourceName): array
    {
        $newAdvisories = [];
        $withdrawnAdvisories = [];

        /** @var array<string, array<string, SecurityAdvisory>> $existingSourceAdvisoryMap */
        $existingSourceAdvisoryMap = [];
        /** @var array<string, SecurityAdvisory[]> $unmatchedExistingAdvisories */
        $unmatchedExistingAdvisories = [];
        foreach ($existingAdvisories as $advisory) {
            $sourceRemoteId = $advisory->getSourceRemoteId($sourceName);
            if ($sourceRemoteId) {
                $existingSourceAdvisoryMap[$advisory->getPackageName()][$sourceRemoteId] = $advisory;
            } else {
                $unmatchedExistingAdvisories[$advisory->getPackageName()][$advisory->getPackagistAdvisoryId()] = $advisory;
            }
        }

        // Attempt to match existing advisories against remote id
        $unmatchedRemoteAdvisories = [];
        foreach ($remoteAdvisories->getPackageNames() as $packageName) {
            foreach ($remoteAdvisories->getAdvisoriesForPackageName($packageName) as $remoteAdvisory) {
                if (isset($existingSourceAdvisoryMap[$packageName][$remoteAdvisory->id])) {
                    $matched = $existingSourceAdvisoryMap[$packageName][$remoteAdvisory->id];
                    $matched->updateAdvisory($remoteAdvisory, !$this->cveClaimedByActiveAdvisory($existingAdvisories, $matched, $remoteAdvisory->cve));
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
        foreach ($unmatchedRemoteAdvisories as $packageName => $packageAdvisories) {
            foreach ($packageAdvisories as $remoteAdvisory) {
                $matchedAdvisory = null;
                $lowestScore = 9999;
                if (isset($unmatchedExistingAdvisories[$packageName])) {
                    foreach ($unmatchedExistingAdvisories[$packageName] as $unmatchedAdvisory) {
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
                        $lowestScore = $score;
                    }
                }

                // No similar existing advisories found. Store them as new advisories
                if ($matchedAdvisory === null) {
                    $newAdvisories[] = new SecurityAdvisory($remoteAdvisory, $sourceName);
                } else {
                    // Update advisory and make sure the new source is added
                    $matchedAdvisory->addSource($remoteAdvisory->id, $sourceName, $remoteAdvisory->severity);
                    $matchedAdvisory->updateAdvisory($remoteAdvisory, !$this->cveClaimedByActiveAdvisory($existingAdvisories, $matchedAdvisory, $remoteAdvisory->cve));
                    unset($unmatchedExistingAdvisories[$packageName][$matchedAdvisory->getPackagistAdvisoryId()]);
                }
            }
        }

        foreach ($unmatchedExistingAdvisories as $packageUnmatchedAdvisories) {
            foreach ($packageUnmatchedAdvisories as $unmatchedAdvisory) {
                if (null === $unmatchedAdvisory->getSourceRemoteId($sourceName)) {
                    continue;
                }

                if ($this->hasOtherSource($unmatchedAdvisory, $sourceName)) {
                    $unmatchedAdvisory->removeSource($sourceName);
                    continue;
                }

                $unmatchedAdvisory->withdraw();
                $withdrawnAdvisories[] = $unmatchedAdvisory;
            }
        }

        return [$newAdvisories, $withdrawnAdvisories];
    }
}
