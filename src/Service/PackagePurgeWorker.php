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

namespace App\Service;

use App\Audit\VersionDeletionReason;
use App\Entity\Job;
use App\Entity\Package;
use App\Entity\User;
use App\Entity\Version;
use App\Entity\VersionRepository;
use App\Model\PackageManager;
use App\Model\ProviderManager;
use Doctrine\Persistence\ManagerRegistry;
use Seld\Signal\SignalHandler;

/**
 * Purges a single package out of band — soft-deleting its versions and removing its published
 * artifacts (provider record, metadata files, CDN, search index) — so the work reliably completes
 * (and can be retried) instead of blocking the request that triggered it, e.g. freezing a
 * spam/malware user who maintains many packages. The caller is responsible for freezing the package
 * (with the appropriate reason) first.
 *
 * Idempotent and keyed by package name: every step is a no-op if already done, and the package row
 * may or may not still exist (a frozen package is kept; a deleted one is not).
 */
class PackagePurgeWorker
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private ProviderManager $providerManager,
        private PackageManager $packageManager,
    ) {
    }

    /**
     * @param Job<PackagePurgeJob> $job
     *
     * @return GenericCompletedResult
     */
    public function process(Job $job, SignalHandler $signal): array
    {
        $payload = $job->getPayload();
        $packageName = $payload['name'];

        $package = $this->doctrine->getRepository(Package::class)->findOneBy(['name' => $packageName]);
        if ($package !== null) {
            $actor = isset($payload['actorId'])
                ? $this->doctrine->getRepository(User::class)->find($payload['actorId'])
                : null;

            /** @var VersionRepository $versionRepo */
            $versionRepo = $this->doctrine->getRepository(Version::class);
            foreach ($package->getVersions() as $version) {
                if (!$version->isSoftDeleted()) {
                    $versionRepo->softDelete($version, VersionDeletionReason::Hidden, 'spam', null, $actor);
                }
            }

            $this->providerManager->deletePackage($package);
            $this->doctrine->getManager()->flush();
        }

        $this->packageManager->deletePackageMetadata($packageName);
        $this->packageManager->deletePackageCdnMetadata($packageName);
        $this->packageManager->deletePackageSearchIndex($packageName);

        return [
            'status' => Job::STATUS_COMPLETED,
            'message' => 'Purged published artifacts for '.$packageName,
        ];
    }
}
