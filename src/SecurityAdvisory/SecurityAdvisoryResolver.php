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
     * @return array{SecurityAdvisory[], SecurityAdvisory[]}
     */
    public function resolve(array $existingAdvisories, RemoteSecurityAdvisoryCollection $remoteAdvisories, string $sourceName): array
    {
        $newAdvisories = [];
        $removedAdvisories = [];

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
                    $existingSourceAdvisoryMap[$packageName][$remoteAdvisory->id]->updateAdvisory($remoteAdvisory);
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
                        if ($score < $lowestScore && $score <= $requiredDifferenceScore) {
                            $matchedAdvisory = $unmatchedAdvisory;
                            $lowestScore = $score;
                        }
                    }
                }

                // No similar existing advisories found. Store them as new advisories
                if ($matchedAdvisory === null) {
                    $newAdvisories[] = new SecurityAdvisory($remoteAdvisory, $sourceName);
                } else {
                    // Update advisory and make sure the new source is added
                    $matchedAdvisory->addSource($remoteAdvisory->id, $sourceName, $remoteAdvisory->severity);
                    $matchedAdvisory->updateAdvisory($remoteAdvisory);
                    unset($unmatchedExistingAdvisories[$packageName][$matchedAdvisory->getPackagistAdvisoryId()]);
                }
            }
        }

        foreach ($unmatchedExistingAdvisories as $packageUnmatchedAdvisories) {
            foreach ($packageUnmatchedAdvisories as $unmatchedAdvisory) {
                if ($unmatchedAdvisory->removeSource($sourceName) && !$unmatchedAdvisory->hasSources()) {
                    $removedAdvisories[] = $unmatchedAdvisory;
                }
            }
        }

        return [$newAdvisories, $removedAdvisories];
    }
}
