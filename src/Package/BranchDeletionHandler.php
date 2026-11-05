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

namespace App\Package;

use App\Audit\VersionDeletionReason;
use App\Entity\Package;
use App\Entity\Version;
use App\Service\Locker;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Applies a branch deletion reported by the VCS host without crawling the package: a deleted branch
 * can change nothing but its own dev version, and the host knows for a fact it is gone, so the
 * version is removed right away instead of being soft-deleted for a day by the next crawl.
 */
class BranchDeletionHandler
{
    use DoctrineTrait;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly Locker $locker,
    ) {
    }

    public function apply(Package $package, string $branch): BranchDeletionOutcome
    {
        // an in-flight update may have listed the branch already and would recreate the version
        if (!$this->locker->lockPackageUpdate($package->getId())) {
            return BranchDeletionOutcome::Deferred;
        }

        try {
            $em = $this->getEM();
            $versionRepo = $em->getRepository(Version::class);

            $version = $versionRepo->findForBranch($package, $branch);
            if (null === $version) {
                return BranchDeletionOutcome::NothingToRemove;
            }

            // dependents, suggesters and the readme are derived from the default branch, so losing
            // it (the stored flag can lag behind a default branch switch) needs a full update
            if ($version->isDefaultBranch()) {
                return BranchDeletionOutcome::Deferred;
            }

            // intentionally-pulled versions are left alone, as the Updater does
            $reason = $version->getDeletionReason();
            if (null !== $reason && VersionDeletionReason::AutoDeletedMissing !== $reason) {
                return BranchDeletionOutcome::NothingToRemove;
            }

            $versionRepo->remove($version);
            $em->flush();

            return BranchDeletionOutcome::Removed;
        } finally {
            $this->locker->unlockPackageUpdate($package->getId());
        }
    }
}
