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

/**
 * The outcome of {@see SecurityAdvisoryResolver::planResolve()}: what should happen to each advisory,
 * computed without mutating anything. The caller applies the withdrawals ({@see
 * SecurityAdvisoryResolver::applyWithdrawals()}) and flushes them before applying the matches
 * ({@see SecurityAdvisoryResolver::applyMatches()}), so a reassigned CVE can reuse the freed
 * (packageName, activeCve) unique key without a constraint violation.
 */
final class SecurityAdvisoryResolvePlan
{
    /**
     * @param SecurityAdvisory[]                                    $existingAdvisories  every advisory the plan was built from
     * @param list<array{SecurityAdvisory, RemoteSecurityAdvisory}> $exactMatches        existing advisory matched to a remote by its source remote id
     * @param list<array{SecurityAdvisory, RemoteSecurityAdvisory}> $renameMatches       existing advisory fuzzy-matched to a remote whose id changed
     * @param list<RemoteSecurityAdvisory>                          $newRemoteAdvisories remote advisories with no local counterpart
     * @param list<SecurityAdvisory>                                $unmatchedExisting   advisories carrying $sourceName that the source no longer lists
     */
    public function __construct(
        public readonly string $sourceName,
        public readonly array $existingAdvisories,
        public readonly array $exactMatches,
        public readonly array $renameMatches,
        public readonly array $newRemoteAdvisories,
        public readonly array $unmatchedExisting,
    ) {
    }
}
