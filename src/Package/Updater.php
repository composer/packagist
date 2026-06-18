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

use App\Audit\AbandonmentReason;
use App\Audit\VersionDeletionReason;
use App\Entity\AuditRecord;
use App\Entity\ConflictLink;
use App\Entity\Dependent;
use App\Entity\DevRequireLink;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\PackageReadme;
use App\Entity\ProvideLink;
use App\Entity\ReplaceLink;
use App\Entity\RequireLink;
use App\Entity\SuggestLink;
use App\Entity\Tag;
use App\Entity\Version;
use App\Entity\VersionRepository;
use App\Event\PackageAbandonedEvent;
use App\Event\VersionReferenceChangedEvent;
use App\HtmlSanitizer\ReadmeImageSanitizer;
use App\HtmlSanitizer\ReadmeLinkSanitizer;
use App\Model\PackageManager;
use App\Model\ProviderManager;
use App\Model\VersionIdCache;
use App\Service\VersionCache;
use App\Util\HttpDownloaderOptionsFactory;
use cebe\markdown\GithubMarkdown;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\CompletePackageInterface;
use Composer\Pcre\Preg;
use Composer\Repository\InvalidRepositoryException;
use Composer\Repository\Vcs\GitHubDriver;
use Composer\Repository\Vcs\GitLabDriver;
use Composer\Repository\Vcs\VcsDriverInterface;
use Composer\Repository\VcsRepository;
use Composer\Util\ErrorHandler;
use Composer\Util\HttpDownloader;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\Event;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Webmozart\Assert\Assert;

final readonly class VersionUpdatedResult
{
    public function __construct(
        public ?int $id,
        public string $version,
        public Version $entity,
        /** @var list<Event> $events */
        public array $events = [],
    ) {
    }
}

final readonly class VersionSkippedResult
{
    public function __construct(
        public int $id,
        public string $version,
        /**
         * True when the skip is because the row is intentionally soft-deleted (admin or maintainer).
         * The dependent/suggester source selection skips these so a pulled version doesn't poison metadata.
         */
        public bool $softDeleted = false,
    ) {
    }
}

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Updater
{
    use \App\Util\DoctrineTrait;

    /**
     * Propagate a source/dist URL change across every version of a package.
     *
     * Stable versions: narrow rewrite via applySourceDistUrlRewrite() — only source.url and
     * dist.url are touched, references/shasum/etc. are left frozen, and the row is skipped
     * (with a warning) if any safety check fails. See applySourceDistUrlRewrite() for details.
     *
     * Dev versions: forces a full row rebuild even when the source.reference is unchanged,
     * so the new URLs (and any other metadata) land on the next crawl. This is the
     * "$flags & UPDATE_SOURCE_DIST_URL" branch in updateInformation().
     *
     * Used after a repo rename/move (CLI --update-source-dist-url, the "Update All" UI button,
     * or the post-rename detection path in ApiController::findGitHubPackagesByRepository()).
     */
    public const UPDATE_SOURCE_DIST_URL = 1;
    public const DELETE_BEFORE = 2;
    public const FORCE_DUMP = 4;

    private const SUPPORTED_LINK_TYPES = [
        'require' => [
            'composer-getter' => 'getRequires',
            'getter' => 'getRequire',
            'setter' => 'addRequireLink',
            'entity' => RequireLink::class,
        ],
        'conflict' => [
            'composer-getter' => 'getConflicts',
            'getter' => 'getConflict',
            'setter' => 'addConflictLink',
            'entity' => ConflictLink::class,
        ],
        'provide' => [
            'composer-getter' => 'getProvides',
            'getter' => 'getProvide',
            'setter' => 'addProvideLink',
            'entity' => ProvideLink::class,
        ],
        'replace' => [
            'composer-getter' => 'getReplaces',
            'getter' => 'getReplace',
            'setter' => 'addReplaceLink',
            'entity' => ReplaceLink::class,
        ],
        'devRequire' => [
            'composer-getter' => 'getDevRequires',
            'getter' => 'getDevRequire',
            'setter' => 'addDevRequireLink',
            'entity' => DevRequireLink::class,
        ],
    ];

    public function __construct(
        private ManagerRegistry $doctrine,
        private ProviderManager $providerManager,
        private VersionIdCache $versionIdCache,
        private MailerInterface $mailer,
        private string $mailFromEmail,
        private UrlGeneratorInterface $urlGenerator,
        private EventDispatcherInterface $eventDispatcher,
        private PackageManager $packageManager,
        private LoggerInterface $logger,
    ) {
        ErrorHandler::register();
    }

    /**
     * Identity used for immutability comparisons: source.reference if present and non-empty,
     * else dist.reference, else null. A version with no effective reference cannot be imported.
     */
    public static function computeEffectiveReference(?string $sourceRef, ?string $distRef): ?string
    {
        if (\is_string($sourceRef) && $sourceRef !== '') {
            return $sourceRef;
        }
        if (\is_string($distRef) && $distRef !== '') {
            return $distRef;
        }

        return null;
    }

    public function __destruct()
    {
        restore_error_handler();
    }

    /**
     * Update a project
     *
     * @param VcsRepository                  $repository       the repository instance used to update from
     * @param int                            $flags            a few of the constants of this class
     * @param ExistingVersionsForUpdate|null $existingVersions
     */
    public function update(IOInterface $io, Config $config, Package $package, VcsRepository $repository, int $flags = 0, ?array $existingVersions = null, ?VersionCache $versionCache = null): Package
    {
        $httpDownloader = new HttpDownloader($io, $config, HttpDownloaderOptionsFactory::getOptions());

        $deleteDate = new \DateTimeImmutable('-1day');

        $em = $this->getEM();

        $driver = $repository->getDriver();
        if (!$driver) {
            throw new \RuntimeException('Driver could not be established for package '.$package->getName().' ('.$package->getRepository().')');
        }

        if ($package->isFrozen()) {
            return $package;
        }

        $remoteId = null;
        if ($driver instanceof GitHubDriver) {
            $repoData = $driver->getRepoData();
            if (isset($repoData['repository']['id'])) {
                $remoteId = 'github.com/'.$repoData['repository']['id'];
            }
        } elseif ($driver instanceof GitLabDriver) {
            $repoData = $driver->getRepoData();
            if (isset($repoData['id'])) {
                $remoteId = 'gitlab.com/'.$repoData['id'];
            }
        }

        if ($remoteId !== null) {
            if (!$package->getRemoteId()) {
                $package->setRemoteId($remoteId);
            }
            if ($package->getRemoteId() !== $remoteId) {
                $package->freeze(PackageFreezeReason::RemoteIdMismatch);
                $em->flush();
                $io->writeError('<error>Skipping update as the source repository has a remote id mismatch. Expected '.$package->getRemoteId().' but got '.$remoteId.'.</error>');

                $message = new Email()
                    ->subject($package->getName().' frozen due to remote id mismatch')
                    ->from(new Address($this->mailFromEmail))
                    ->to($this->mailFromEmail)
                    ->text('Check out '.$this->urlGenerator->generate('view_package', ['name' => $package->getName()], UrlGeneratorInterface::ABSOLUTE_URL).' was not repo-jacked.')
                ;
                $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');
                $this->mailer->send($message);

                return $package;
            }
        }

        $rootIdentifier = $driver->getRootIdentifier();

        // always update the master branch / root identifier, as in case a package gets archived
        // we want to mark it abandoned automatically, but there will not be a new commit to trigger
        // an update
        if ($rootIdentifier !== '' && $versionCache) {
            $versionCache->clearVersion($rootIdentifier);
        }
        // migrate old packages to the new metadata storage for v2
        if ($versionCache && ($package->getUpdatedAt() === null || $package->getUpdatedAt() < new \DateTime('2020-06-20 00:00:00'))) {
            $versionCache->clearVersion('master');
            $versionCache->clearVersion('default');
            $versionCache->clearVersion('trunk');
        }

        $versions = $repository->getPackages();
        usort($versions, static function ($a, $b) {
            $aVersion = $a->getVersion();
            $bVersion = $b->getVersion();
            if ($aVersion === '9999999-dev' || 'dev-' === substr($aVersion, 0, 4)) {
                $aVersion = 'dev';
            }
            if ($bVersion === '9999999-dev' || 'dev-' === substr($bVersion, 0, 4)) {
                $bVersion = 'dev';
            }
            $aIsDev = $aVersion === 'dev' || substr($aVersion, -4) === '-dev';
            $bIsDev = $bVersion === 'dev' || substr($bVersion, -4) === '-dev';

            // push dev versions to the end
            if ($aIsDev !== $bIsDev) {
                return $aIsDev ? 1 : -1;
            }

            // equal versions are sorted by date
            if ($aVersion === $bVersion) {
                return $a->getReleaseDate() > $b->getReleaseDate() ? 1 : -1;
            }

            // the rest is sorted by version
            return version_compare($aVersion, $bVersion);
        });

        $versionRepository = $this->doctrine->getRepository(Version::class);

        if ($flags & self::DELETE_BEFORE) {
            // Stable, published versions are immutable historical records and must never be
            // hard-deleted. DELETE_BEFORE wipes the dev-branch trackers and lets the normal update
            // path reconcile stable rows via the immutability gate (matching refs are skipped,
            // diverging refs are blocked + audited).
            // Source the rows from the DB rather than $package->getVersions() — the in-memory
            // collection state depends on how the caller loaded the package, and we must not
            // silently skip removals just because the collection isn't lazy-loaded yet.
            /** @var list<Version> $devVersions */
            $devVersions = $versionRepository->createQueryBuilder('v')
                ->where('v.package = :package')
                ->andWhere('v.development = true')
                ->setParameter('package', $package)
                ->getQuery()->getResult();
            foreach ($devVersions as $version) {
                $versionRepository->remove($version);
            }

            $em->flush();
            $em->refresh($package);
        }

        if (!$existingVersions) {
            $existingVersions = $versionRepository->getVersionMetadataForUpdate($package);
        }

        $processedVersions = [];
        $idsToMarkUpdated = [];

        /** @var int|null $dependentSuggesterSource Version id to use as dependent/suggester source */
        $dependentSuggesterSource = null;
        foreach ($versions as $version) {
            if ($version instanceof AliasPackage) {
                continue;
            }
            if (!$version instanceof CompletePackageInterface) {
                throw new \LogicException('Received a package instance of type '.$version::class.', expected a CompletePackageInterface instance');
            }

            if (isset($processedVersions[strtolower($version->getVersion())])) {
                $io->write('Skipping version '.$version->getPrettyVersion().' (duplicate of '.$processedVersions[strtolower($version->getVersion())]->getPrettyVersion().')', true, IOInterface::VERBOSE);
                continue;
            }
            $processedVersions[strtolower($version->getVersion())] = $version;

            $result = $this->updateInformation($io, $versionRepository, $package, $existingVersions, $version, $flags, $rootIdentifier, $driver);
            if ($result === null) {
                // version was rejected outright (no usable reference and not in DB)
                continue;
            }

            $versionId = null;
            $versionSoftDeleted = false;
            // A row was created or mutated. This also covers the stable source/dist URL rewrite from
            // applyStableImmutabilityGate() under UPDATE_SOURCE_DIST_URL — that path mutates the managed
            // entity and returns a VersionUpdatedResult so it is flushed + detached here rather than
            // leaning on the catch-all flush at the end of update(). Such rewrites carry no events.
            if ($result instanceof VersionUpdatedResult) {
                foreach ($result->events as $event) {
                    $this->eventDispatcher->dispatch($event);
                }

                $em->flush();

                // detach version once flushed to avoid gathering lots of data in memory
                $em->detach($result->entity);

                $this->versionIdCache->insertVersion($package, $result->entity);
                $versionId = $result->entity->getId();
            } else {
                // idsToMarkUpdated feeds the recovery query below, which un-soft-deletes any of these ids
                // that had been auto-soft-deleted-as-missing and have now reappeared upstream. A row taking
                // the rewrite branch above is an active version present upstream, so for it that recovery
                // would be a no-op — which is why not adding rewritten ids here is safe. (The lone gap: a
                // stable row that was auto-soft-deleted and reappears under UPDATE_SOURCE_DIST_URL gets its
                // URL rewritten but stays soft-deleted; the next normal crawl recovers it via this path.)
                $idsToMarkUpdated[] = $result->id;
                $versionSoftDeleted = $result->softDeleted;
                // Skipped non-soft-deleted rows (immutable stable, ref-unchanged, ...) still need to
                // be a valid dependent/suggester source — they ARE the canonical existing row for
                // this version. Carrying $result->id here avoids pinning $dependentSuggesterSource
                // to a falsy value on the very first stable iteration and silently disabling the
                // Dependent::updateDependentSuggesters() call below.
                if (!$versionSoftDeleted) {
                    $versionId = $result->id;
                }
            }

            // pick the default branch when present, otherwise the first non-soft-deleted version
            if ($version->isDefaultBranch() && !$versionSoftDeleted) {
                $dependentSuggesterSource = $versionId;
            } elseif (null === $dependentSuggesterSource && !$versionSoftDeleted) {
                $dependentSuggesterSource = $versionId;
            }

            // mark the version processed so we can prune leftover ones
            unset($existingVersions[$result->version]);
        }

        if ($dependentSuggesterSource !== null) {
            $this->doctrine->getRepository(Dependent::class)->updateDependentSuggesters($package->getId(), $dependentSuggesterSource);
        }

        // auto-recover versions still present upstream that had been auto-soft-deleted as missing.
        // Admin/maintainer-pulled rows (deletionReason in (admin, maintainer)) stay soft-deleted.
        $em->getConnection()->executeStatement(
            'UPDATE package_version
                SET updatedAt = :now, softDeletedAt = NULL, deletionReason = NULL, deletionReasonText = NULL, internalDeletionReasonText = NULL
                WHERE id IN (:ids)
                  AND softDeletedAt IS NOT NULL
                  AND (deletionReason IS NULL OR deletionReason = :autoReason)',
            ['now' => date('Y-m-d H:i:s'), 'autoReason' => VersionDeletionReason::AutoDeletedMissing->value, 'ids' => $idsToMarkUpdated],
            ['ids' => ArrayParameterType::INTEGER]
        );

        // remove or soft-mark versions that disappeared from upstream
        foreach ($existingVersions as $version) {
            $existingReason = $version['deletionReason'] ?? null;
            $isAutoOrEmpty = $existingReason === null || $existingReason === VersionDeletionReason::AutoDeletedMissing->value;
            $isDev = (bool) $version['development'];

            // Intentionally-pulled versions (admin/maintainer/hidden): leave them as they are.
            if (!$isAutoOrEmpty) {
                continue;
            }

            if (
                $isDev
                && (
                    // Dev versions track branches and may be hard-purged after a 1-day grace period
                    (null !== $version['softDeletedAt'] && new \DateTime($version['softDeletedAt']) < $deleteDate)
                    // ... or immediately if they're legacy v1-normalized dev-master/trunk/default rows that
                    // got re-created under a non-normalized name
                    || ($version['normalizedVersion'] === '9999999-dev')
                )
            ) {
                $versionEntity = $versionRepository->find($version['id']);
                if (null !== $versionEntity) {
                    $versionRepository->remove($versionEntity);
                }
                continue;
            }

            // First-time soft-mark only. Re-stamping softDeletedAt on every crawl would reset the
            // 1-day grace window for dev rows and is meaningless noise for stable rows.
            if (null === $version['softDeletedAt']) {
                $em->getConnection()->executeStatement(
                    'UPDATE package_version SET softDeletedAt = :now, deletionReason = :reason WHERE id = :id',
                    ['now' => date('Y-m-d H:i:s'), 'reason' => VersionDeletionReason::AutoDeletedMissing->value, 'id' => $version['id']]
                );
            }
        }

        if (null !== ($match = $package->getGitHubComponents())) {
            $this->updateGitHubInfo($httpDownloader, $package, $match[1], $match[2], $driver);
        } elseif (null !== ($match = $package->getGitLabComponents())) {
            $this->updateGitLabInfo($httpDownloader, $io, $package, $match[1], $match[2], $driver);
        } else {
            $this->updateReadme($io, $package, $driver);
        }

        $usingDetails = '';
        try {
            $gitDriverProperty = new \ReflectionProperty($driver, 'gitDriver');
            if (null !== $gitDriverProperty->getValue($driver)) {
                $usingDetails = ' (via GitDriver fallback instance)';
            }
        } catch (\Throwable $e) {
        }
        $io->writeError('Updated from '.$package->getRepository().' using '.$driver::class.$usingDetails);

        // make sure the package exists in the package list if for some reason adding it on submit failed
        if (!$this->providerManager->packageExists($package->getName())) {
            $this->providerManager->insertPackage($package);
        }

        $package->setUpdatedAt(new \DateTimeImmutable());
        $package->setCrawledAt(new \DateTimeImmutable());

        if ($flags & self::FORCE_DUMP) {
            $package->setDumpedAt(null);
            $package->setDumpedAtV2(null);
        }

        $em->flush();
        if ($repository->hadInvalidBranches()) {
            throw new InvalidRepositoryException('Some branches contained invalid data and were discarded, it is advised to review the log and fix any issues present in branches');
        }

        return $package;
    }

    /**
     * Apply the immutability rule to an existing stable version. Usually returns a VersionSkippedResult:
     * the reference is frozen, so an upstream re-tag is refused (and audited/emailed once per attempted
     * ref). The reference is never changed here.
     *
     * The one exception is the operator-gated UPDATE_SOURCE_DIST_URL flag: when the ref is unchanged it
     * may rewrite source.url/dist.url in place (references and shasum stay put). In that case the row was
     * actually mutated, so it returns a VersionUpdatedResult carrying the entity, signalling the caller to
     * store it. Other DB writes performed here are to lastBlockedReference (set or clear) and the
     * audit/email side-effects when a new attempted ref is detected.
     *
     * @param array{id: int, version: string, normalizedVersion: string, development: int, source: array{type: string|null, url: string|null, reference: string|null}|null, dist: array{type: string|null, url: string|null, reference: string|null, shasum: string|null}|null, softDeletedAt: string|null, deletionReason: string|null, lastBlockedReference: string|null, defaultBranch: int} $existingVersion
     */
    private function applyStableImmutabilityGate(IOInterface $io, \Doctrine\ORM\EntityManagerInterface $em, VersionRepository $versionRepo, Package $package, array $existingVersion, CompletePackageInterface $data, int $flags, VcsDriverInterface $driver, ?string $newEffectiveRef): VersionSkippedResult|VersionUpdatedResult
    {
        $normVersion = $data->getVersion();
        $prettyVersion = $data->getPrettyVersion();
        $oldEffectiveRef = self::computeEffectiveReference($existingVersion['source']['reference'] ?? null, $existingVersion['dist']['reference'] ?? null);
        $lastBlocked = $existingVersion['lastBlockedReference'];

        // Incoming data with no usable reference: refuse to mutate. Don't treat as a block event
        // (broken driver output, not an upstream re-tag).
        if ($newEffectiveRef === null) {
            $io->writeError('<warning>Skipping update of '.$prettyVersion.': incoming data has no usable source/dist reference.</warning>');

            return new VersionSkippedResult(id: $existingVersion['id'], version: strtolower($normVersion));
        }

        if ($oldEffectiveRef === $newEffectiveRef) {
            // Divergence resolved: clear lastBlockedReference so the UI badge disappears and a future
            // re-divergence to this same ref correctly re-fires the audit/email path.
            if ($lastBlocked !== null) {
                $em->getConnection()->executeStatement(
                    'UPDATE package_version SET lastBlockedReference = NULL WHERE id = :id',
                    ['id' => $existingVersion['id']]
                );
            }

            if ($flags & self::UPDATE_SOURCE_DIST_URL) {
                $rewritten = $this->applySourceDistUrlRewrite($io, $versionRepo, $existingVersion, $data, $driver);
                if (null !== $rewritten) {
                    // The row's source/dist URL was actually rewritten (the reference is unchanged, so
                    // immutability still holds). Surface it as an update, not a skip, so the caller stores
                    // it through the VersionUpdatedResult branch (explicit flush + detach) instead of
                    // relying on the catch-all flush at the end of update().
                    return new VersionUpdatedResult(id: $existingVersion['id'], version: strtolower($normVersion), entity: $rewritten);
                }
            }

            return new VersionSkippedResult(id: $existingVersion['id'], version: strtolower($normVersion));
        }

        // Reference differs: this is an immutability violation. Dedupe by the attempted ref so the
        // maintainer log and audit table don't accumulate duplicate entries on every crawl.
        if ($lastBlocked === $newEffectiveRef) {
            return new VersionSkippedResult(id: $existingVersion['id'], version: strtolower($normVersion));
        }

        $io->writeError('<warning>Refusing to update stable version '.$prettyVersion.': reference changed (was '.($oldEffectiveRef ?? '<none>').', now '.$newEffectiveRef.'). Stable versions are immutable; tag a new version to publish changes.</warning>');

        // Audit + email + remember the attempted ref.
        $em->persist(AuditRecord::versionReferenceChangeBlocked($package, $prettyVersion, $oldEffectiveRef, $newEffectiveRef));
        $em->getConnection()->executeStatement(
            'UPDATE package_version SET lastBlockedReference = :ref WHERE id = :id',
            ['ref' => $newEffectiveRef, 'id' => $existingVersion['id']]
        );
        $this->packageManager->notifyVersionReferenceChangeBlocked($package, $prettyVersion, $oldEffectiveRef, $newEffectiveRef);

        return new VersionSkippedResult(id: $existingVersion['id'], version: strtolower($normVersion));
    }

    /**
     * Strict source/dist URL rewrite for stable versions under UPDATE_SOURCE_DIST_URL.
     * Rewrites only source.url and dist.url; leaves references, shasum, and every other field alone.
     * Skips the row (with a specific warning) if any of the safety checks fail.
     *
     * Returns the mutated (still managed) Version when a rewrite was applied, or null when a safety
     * check caused the row to be skipped. The caller decides how to surface that (updated vs skipped).
     *
     * @param array{id: int, source: array{type: string|null, url: string|null, reference: string|null}|null, dist: array{type: string|null, url: string|null, reference: string|null, shasum: string|null}|null} $existingVersion
     */
    private function applySourceDistUrlRewrite(IOInterface $io, VersionRepository $versionRepo, array $existingVersion, CompletePackageInterface $data, VcsDriverInterface $driver): ?Version
    {
        $prettyVersion = $data->getPrettyVersion();
        $skip = function (string $reason) use ($io, $prettyVersion, $data, $existingVersion): void {
            $this->logger->error('Skipped URL update for '.$data->getName(), ['reason' => $reason, 'prettyVersion' => $prettyVersion, 'old' => $existingVersion, 'new' => $data]);
            $io->writeError('<warning>Skipping URL update on '.$prettyVersion.': '.$reason.'.</warning>');
        };

        $oldSource = $existingVersion['source'] ?? null;
        $oldDist = $existingVersion['dist'] ?? null;
        $oldSourceRef = $oldSource['reference'] ?? null;
        $oldDistRef = $oldDist['reference'] ?? null;
        $newSourceRef = $data->getSourceReference();
        $newDistRef = $data->getDistReference();
        $newSourceUrl = $data->getSourceUrl();
        $newDistUrl = $data->getDistUrl();

        if (!\is_string($oldSourceRef) || $oldSourceRef === '' || !\is_string($oldDistRef) || $oldDistRef === '') {
            $skip('existing source/dist reference is missing');

            return null;
        }
        if (!\is_string($newSourceRef) || $newSourceRef === '' || !\is_string($newDistRef) || $newDistRef === '') {
            $skip('new source/dist reference is missing');

            return null;
        }
        if (!Preg::isMatch('{^[a-f0-9]{40,}$}', $oldSourceRef) || !Preg::isMatch('{^[a-f0-9]{40,}$}', $oldDistRef)) {
            $skip('reference is not a 40+ char commit hash');

            return null;
        }
        if ($oldSourceRef !== $newSourceRef || $oldDistRef !== $newDistRef) {
            $skip('source/dist reference hashes differ between stored and new data');

            return null;
        }
        if (!\is_string($newSourceUrl) || $newSourceUrl === '' || !\is_string($newDistUrl) || $newDistUrl === '') {
            $skip('new source/dist URL is missing');

            return null;
        }

        // Authoritative check: ask the VCS driver what dist URL belongs to this reference.
        try {
            $driverDist = $driver->getDist($newDistRef);
        } catch (\Throwable $e) {
            $skip('driver getDist() threw: '.$e->getMessage());

            return null;
        }
        if (!is_array($driverDist) || ($driverDist['url'] ?? null) !== $newDistUrl) {
            $skip('driver-confirmed dist URL does not match incoming dist URL');

            return null;
        }

        $version = $versionRepo->find($existingVersion['id']);
        if (null === $version) {
            $skip('version not found by id');

            return null;
        }

        Assert::notNull($oldSource);
        Assert::notNull($oldDist);
        $oldSourceUrl = $oldSource['url'] ?? null;
        $oldDistUrlStored = $oldDist['url'] ?? null;

        // dist.url was cross-checked against the driver above; source.url is taken from the incoming
        // data without an equivalent driver check. That asymmetry is acceptable here: this path is
        // operator-gated (UPDATE_SOURCE_DIST_URL only) and the reference hashes are pinned to the
        // frozen snapshot, so only the URL can move, never the ref.
        $newSource = $oldSource;
        $newSource['url'] = $newSourceUrl;
        $newDist = $oldDist;
        $newDist['url'] = $newDistUrl;

        $version->setSource($newSource);
        $version->setDist($newDist);
        $version->setUpdatedAt(new \DateTimeImmutable());

        $this->logger->info('Rewrote source/dist URL for stable version', [
            'package' => $data->getName(),
            'version' => $prettyVersion,
            'version_id' => $existingVersion['id'],
            'source_url_from' => $oldSourceUrl,
            'source_url_to' => $newSourceUrl,
            'dist_url_from' => $oldDistUrlStored,
            'dist_url_to' => $newDistUrl,
            'reference' => $newSourceRef,
        ]);

        return $version;
    }

    /**
     * Decide what to do with one upstream version vs the DB state, and apply the change.
     *
     * Returns:
     *  - null when the version is rejected outright (e.g. brand-new but no usable reference)
     *  - VersionSkippedResult when the existing row is left alone (immutable stable, soft-deleted, etc.)
     *  - VersionUpdatedResult when the row is created or mutated
     *
     * @param ExistingVersionsForUpdate $existingVersions
     */
    private function updateInformation(IOInterface $io, VersionRepository $versionRepo, Package $package, array $existingVersions, CompletePackageInterface $data, int $flags, string $rootIdentifier, VcsDriverInterface $driver): VersionSkippedResult|VersionUpdatedResult|null
    {
        $em = $this->getEM();
        $version = new Version();
        $versionId = null;
        $postUpdateEvents = [];

        $normVersion = $data->getVersion();
        $newSourceRef = $data->getSourceReference();
        $newDistRef = $data->getDistReference();
        $newEffectiveRef = self::computeEffectiveReference($newSourceRef, $newDistRef);

        $existingVersion = $existingVersions[strtolower($normVersion)] ?? null;

        if ($existingVersion) {
            // Intentionally-pulled versions are never recreated or modified by the Updater
            $existingReason = isset($existingVersion['deletionReason']) ? VersionDeletionReason::tryFrom((string) $existingVersion['deletionReason']) : null;
            if ($existingReason !== null && $existingReason !== VersionDeletionReason::AutoDeletedMissing) {
                return new VersionSkippedResult(
                    id: $existingVersion['id'],
                    version: strtolower($normVersion),
                    softDeleted: true,
                );
            }

            // Stable-version immutability gate: any existing stable version is frozen.
            if (!$data->isDev()) {
                return $this->applyStableImmutabilityGate($io, $em, $versionRepo, $package, $existingVersion, $data, $flags, $driver, $newEffectiveRef);
            }
        } elseif ($newEffectiveRef === null) {
            // Brand-new version with no usable identity: refuse to create.
            $io->writeError('<warning>Skipping '.$data->getPrettyVersion().': no usable source/dist reference.</warning>');

            return null;
        }

        if ($existingVersion) {
            // Dev-version flow (existing version). Update on reference change, or abandoned flags & default-branch status changes.
            if (
                ($existingVersion['source']['reference'] ?? null) !== $newSourceRef
                || ($flags & self::UPDATE_SOURCE_DIST_URL)
                || ($data->isAbandoned() && !$package->isAbandoned())
                || ($data->isAbandoned() && $data->getReplacementPackage() !== $package->getReplacementPackage())
            ) {
                $version = $versionRepo->find($existingVersion['id']);
                if (null === $version) {
                    throw new \LogicException('At this point a version should always be found');
                }
                $versionId = $version->getId();
            } elseif ($data->isDefaultBranch() !== (bool) $existingVersion['defaultBranch']) {
                // if the version default branch state has changed we update just that
                $version = $versionRepo->find($existingVersion['id']);
                if (null === $version) {
                    throw new \LogicException('At this point a version should always be found');
                }
                $versionId = $version->getId();
                $version->setIsDefaultBranch($data->isDefaultBranch());
                $em->persist($version);

                return new VersionUpdatedResult(
                    id: $versionId,
                    version: strtolower($normVersion),
                    entity: $version,
                );
            } else {
                return new VersionSkippedResult(
                    id: $existingVersion['id'],
                    version: strtolower($normVersion),
                );
            }
        }

        // Capture original metadata BEFORE modifications
        $originalMetadata = $versionId !== null ? $version->toV2Array([]) : null;

        $version->setName($package->getName());
        $version->setVersion($data->getPrettyVersion());
        $version->setNormalizedVersion($normVersion);
        $version->setDevelopment($data->isDev());
        $version->setPhpExt($data->getPhpExt());

        $em->persist($version);

        $descr = $this->sanitize($data->getDescription());
        $version->setDescription($descr);
        $version->setIsDefaultBranch($data->isDefaultBranch());

        // update the package description only for the default branch
        if ($data->isDefaultBranch()) {
            $package->setDescription($descr);
            $package->setType($this->sanitize($data->getType()));
            if ($data->isAbandoned() && !$package->isAbandoned()) {
                $io->write('Marking package abandoned as per composer metadata from '.$version->getVersion());
                $package->setAbandoned(true);
                $postUpdateEvents[] = new PackageAbandonedEvent($package, $this->detectAbandonmentReason($driver, $rootIdentifier));
                if ($data->getReplacementPackage()) {
                    $package->setReplacementPackage($data->getReplacementPackage());
                }
            }
        }

        $version->setHomepage($this->filterUrl($data->getHomepage()));
        $version->setLicense($data->getLicense() ?: []);

        $version->setPackage($package);
        $version->setUpdatedAt(new \DateTimeImmutable());
        $version->setSoftDeletedAt(null);
        $version->setDeletionReason(null);
        $version->setDeletionReasonText(null);
        $version->setInternalDeletionReasonText(null);
        $version->setReleasedAt($data->getReleaseDate() === null ? null : \DateTimeImmutable::createFromInterface($data->getReleaseDate()));

        if ($data->getSourceType() && !in_array($data->getSourceType(), ['perforce', 'fossil'], true)) { // null or '' here explicitly means no source and will be nulled, do not change this behavior
            $source['type'] = $data->getSourceType();
            $source['url'] = $data->getSourceUrl();
            // force public URLs even if the package somehow got downgraded to a GitDriver
            if (\is_string($source['url']) && Preg::isMatch('{^git@github.com:(?P<repo>.*?)\.git$}', $source['url'], $match)) {
                $source['url'] = 'https://github.com/'.$match['repo'];
            }
            $source['reference'] = $data->getSourceReference();
            $version->setSource($source);
        } else {
            $version->setSource(null);
        }

        if ($data->getDistType()) {
            $dist['type'] = $data->getDistType();
            $dist['url'] = $data->getDistUrl();
            $dist['reference'] = $data->getDistReference();
            $dist['shasum'] = $data->getDistSha1Checksum();
            $version->setDist($dist);
        } else {
            $version->setDist(null);
        }

        if ($data->getType()) {
            $type = $this->sanitize($data->getType());
            $version->setType($type);
            if (null === $package->getType()) {
                $package->setType($type);
            }
        }

        $version->setTargetDir($data->getTargetDir());
        $version->setAutoload($data->getAutoload());
        $version->setExtra($data->getExtra());
        $version->setBinaries($data->getBinaries());
        $version->setIncludePaths($data->getIncludePaths());
        $version->setSupport($this->filterSupportUrls($data->getSupport()));
        $version->setFunding($this->filterFundingUrls($data->getFunding()));

        if ($data->getKeywords()) {
            $keywords = [];
            foreach ($data->getKeywords() as $keyword) {
                $keywords[mb_strtolower($keyword, 'UTF-8')] = $keyword;
            }

            $existingTags = [];
            foreach ($version->getTags() as $tag) {
                $existingTags[mb_strtolower($tag->getName(), 'UTF-8')] = $tag;
            }

            foreach ($keywords as $tagKey => $keyword) {
                if (isset($existingTags[$tagKey])) {
                    unset($existingTags[$tagKey]);
                    continue;
                }

                $tag = Tag::getByName($em, $keyword, true);
                if (!$version->getTags()->contains($tag)) {
                    $version->addTag($tag);
                }
            }

            foreach ($existingTags as $tag) {
                $version->getTags()->removeElement($tag);
            }
        } elseif (\count($version->getTags())) {
            $version->getTags()->clear();
        }

        $version->setAuthors([]);
        if ($data->getAuthors()) {
            $authors = [];
            foreach ($data->getAuthors() as $authorData) {
                $author = [];

                foreach (['email', 'name', 'homepage', 'role'] as $field) {
                    if (isset($authorData[$field])) {
                        $author[$field] = trim($authorData[$field]);
                        if ('homepage' === $field) {
                            $author[$field] = (string) $this->filterUrl($author[$field]);
                        }
                        if ('' === $author[$field]) {
                            unset($author[$field]);
                        }
                    }
                }

                // skip authors with no information
                if (!isset($authorData['email']) && !isset($authorData['name'])) {
                    continue;
                }

                $authors[] = $author;
            }
            $version->setAuthors($authors);
        }

        // handle links
        foreach (self::SUPPORTED_LINK_TYPES as $opts) {
            $links = [];
            foreach ($data->{$opts['composer-getter']}() as $link) {
                $constraint = $link->getPrettyConstraint();
                if (str_contains($constraint, ',') && str_contains($constraint, '@')) {
                    $constraint = Preg::replaceCallbackStrictGroups('{([><]=?\s*[^@]+?)@([a-z]+)}i', static function ($matches) {
                        if ($matches[2] === 'stable') {
                            return $matches[1];
                        }

                        return $matches[1].'-'.$matches[2];
                    }, $constraint);
                }

                $links[$link->getTarget()] = $constraint;
            }

            foreach ($version->{$opts['getter']}() as $link) {
                // clear links that have changed/disappeared (for updates)
                if (!isset($links[$link->getPackageName()]) || $links[$link->getPackageName()] !== $link->getPackageVersion()) {
                    $version->{$opts['getter']}()->removeElement($link);
                    $em->remove($link);
                } else {
                    // clear those that are already set
                    unset($links[$link->getPackageName()]);
                }
            }

            foreach ($links as $linkPackageName => $linkPackageVersion) {
                $class = $opts['entity'];
                $link = new $class();
                $link->setPackageName((string) $linkPackageName);
                $link->setPackageVersion($linkPackageVersion);
                $version->{$opts['setter']}($link);
                $link->setVersion($version);
                $em->persist($link);
            }
        }

        // handle suggests
        if ($suggests = $data->getSuggests()) {
            foreach ($version->getSuggest() as $link) {
                // clear links that have changed/disappeared (for updates)
                if (!isset($suggests[$link->getPackageName()]) || $suggests[$link->getPackageName()] !== $link->getPackageVersion()) {
                    $version->getSuggest()->removeElement($link);
                    $em->remove($link);
                } else {
                    // clear those that are already set
                    unset($suggests[$link->getPackageName()]);
                }
            }

            foreach ($suggests as $linkPackageName => $linkPackageVersion) {
                $link = new SuggestLink();
                $link->setPackageName($linkPackageName);
                $link->setPackageVersion($linkPackageVersion);
                $version->addSuggestLink($link);
                $link->setVersion($version);
                $em->persist($link);
            }
        } elseif (\count($version->getSuggest())) {
            // clear existing suggests if present
            foreach ($version->getSuggest() as $link) {
                $em->remove($link);
            }
            $version->getSuggest()->clear();
        }

        if ($originalMetadata !== null) {
            $event = new VersionReferenceChangedEvent($version, $originalMetadata);

            if ($event->hasReferenceChanged()) {
                $postUpdateEvents[] = $event;
            }
        }

        return new VersionUpdatedResult(
            id: $versionId,
            version: strtolower($normVersion),
            entity: $version,
            events: $postUpdateEvents,
        );
    }

    /**
     * Update the readme for $package from $repository.
     */
    private function updateReadme(IOInterface $io, Package $package, VcsDriverInterface $driver): void
    {
        // GitHub readme & info handled separately in updateGitHubInfo, sweep the special attributes
        $package->setGitHubStars(null);
        $package->setGitHubWatches(null);
        $package->setGitHubForks(null);
        $package->setGitHubOpenIssues(null);

        try {
            $composerInfo = $driver->getComposerInformation($driver->getRootIdentifier());
            if (isset($composerInfo['readme']) && \is_string($composerInfo['readme'])) {
                $readmeFile = $composerInfo['readme'];
            } else {
                $readmeFile = 'README.md';
            }

            $ext = substr($readmeFile, (int) strrpos($readmeFile, '.'));
            if ($ext === $readmeFile) {
                $ext = '.txt';
            }

            switch ($ext) {
                case '.txt':
                    $source = $driver->getFileContent($readmeFile, $driver->getRootIdentifier());
                    if (!empty($source)) {
                        $this->updatePackageReadme($package, '<pre>'.htmlspecialchars($source).'</pre>');
                    } else {
                        $this->updatePackageReadme($package, null);
                    }
                    break;

                case '.md':
                    $source = $driver->getFileContent($readmeFile, $driver->getRootIdentifier());
                    if (!empty($source)) {
                        $parser = new GithubMarkdown();
                        $readme = $parser->parse($source);

                        if (!empty($readme)) {
                            if (Preg::isMatch('{^(?:git://|git@|https?://)(gitlab.com|bitbucket.org)[:/]([^/]+)/(.+?)(?:\.git|/)?$}i', $package->getRepository(), $match)) {
                                $this->updatePackageReadme($package, $this->prepareReadme($readme, $match[1], $match[2], $match[3]));
                            } else {
                                $this->updatePackageReadme($package, $this->prepareReadme($readme));
                            }
                        }
                    }
                    break;
            }
        } catch (\Exception $e) {
            // we ignore all errors for this minor function
            $io->write(
                'Can not update readme. Error: '.$e->getMessage(),
                true,
                IOInterface::VERBOSE
            );
        }
    }

    private function updatePackageReadme(Package $package, ?string $contents): void
    {
        $readme = $this->getEM()->find(PackageReadme::class, $package->getId());

        if ($contents === '' || $contents === null) {
            if ($readme) {
                $this->getEM()->remove($readme);
            }

            return;
        }

        if (!$readme) {
            $readme = new PackageReadme($package, $contents);
        } else {
            $readme->contents = $contents;
        }

        $this->getEM()->persist($readme);
    }

    private function updateGitHubInfo(HttpDownloader $httpDownloader, Package $package, string $owner, string $repo, VcsDriverInterface $driver): void
    {
        if (!$driver instanceof GitHubDriver) {
            return;
        }

        $baseApiUrl = 'https://api.github.com/repos/'.$owner.'/'.$repo;

        $repoData = $driver->getRepoData();

        if (!empty($repoData['language']) && \is_string($repoData['language'])) {
            $package->setLanguage($repoData['language']);
        }
        if (isset($repoData['stargazers_count']) && is_numeric($repoData['stargazers_count'])) {
            $package->setGitHubStars((int) $repoData['stargazers_count']);
        }
        if (isset($repoData['subscribers_count']) && is_numeric($repoData['subscribers_count'])) {
            $package->setGitHubWatches((int) $repoData['subscribers_count']);
        }
        if (isset($repoData['network_count']) && is_numeric($repoData['network_count'])) {
            $package->setGitHubForks((int) $repoData['network_count']);
        }
        if (isset($repoData['open_issues_count']) && is_numeric($repoData['open_issues_count'])) {
            $package->setGitHubOpenIssues((int) $repoData['open_issues_count']);
        }

        try {
            $opts = ['http' => ['header' => ['Accept: application/vnd.github.html+json', 'X-GitHub-Api-Version: 2022-11-28']]];
            $readme = $httpDownloader->get($baseApiUrl.'/readme', $opts)->getBody();
        } catch (\Exception $e) {
            if (!$e instanceof \Composer\Downloader\TransportException || $e->getCode() !== 404) {
                return;
            }
            // 404s just mean no readme present so we proceed with the rest
            $readme = null;
        }

        // The content of all readmes, regardless of file type,
        // is returned as HTML by GitHub API
        $this->updatePackageReadme($package, $this->prepareReadme($readme, 'github.com', $owner, $repo));
    }

    private function updateGitLabInfo(HttpDownloader $httpDownloader, IOInterface $io, Package $package, string $owner, string $repo, VcsDriverInterface $driver): void
    {
        // GitLab provides a generic URL for the original formatted README,
        // which requires further elaboration. Here we use the already existing
        // function to handle it, and back here to populate the other available
        // metadata
        $this->updateReadme($io, $package, $driver);

        if (!$driver instanceof GitLabDriver) {
            return;
        }

        $repoData = $driver->getRepoData();

        if (isset($repoData['star_count']) && is_numeric($repoData['star_count'])) {
            $package->setGitHubStars((int) $repoData['star_count']);
        }
        if (isset($repoData['forks_count']) && is_numeric($repoData['forks_count'])) {
            $package->setGitHubForks((int) $repoData['forks_count']);
        }
        if (isset($repoData['open_issues_count']) && is_numeric($repoData['open_issues_count'])) {
            $package->setGitHubOpenIssues((int) $repoData['open_issues_count']);
        }

        // GitLab does not include a "watch" feature
        $package->setGitHubWatches(null);
    }

    /**
     * Prepare the readme by stripping elements and attributes that are not supported .
     */
    private function prepareReadme(?string $readme, ?string $host = null, ?string $owner = null, ?string $repo = null): ?string
    {
        if ($readme === null) {
            return null;
        }

        // detect base path for github readme if file is located in a subfolder like docs/README.md
        $basePath = '';
        if ($host === 'github.com' && Preg::isMatchStrictGroups('{^<div id="readme" [^>]+?data-path="([^"]+)"}', $readme, $match) && str_contains($match[1], '/')) {
            $basePath = \dirname($match[1]);
        }
        if ($basePath) {
            $basePath .= '/';
        }

        $elements = [
            'p',
            'br',
            'small',
            'strong', 'b',
            'em', 'i',
            'strike',
            'sub', 'sup',
            'ins', 'del',
            'ol', 'ul', 'li',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'dl', 'dd', 'dt',
            'pre', 'code', 'samp', 'kbd',
            'q', 'blockquote', 'abbr', 'cite',
            'table', 'thead', 'tbody', 'tr',
            'span',
            'summary',
        ];

        $config = (new HtmlSanitizerConfig());
        $config = $config->defaultAction(HtmlSanitizerAction::Block);

        foreach ($elements as $el) {
            $config = $config->allowElement($el);
        }

        $config = $config
            ->allowElement('img', ['src', 'title', 'alt', 'width', 'height'])
            ->allowElement('a', ['href', 'target', 'id'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowElement('th', ['colspan', 'rowspan'])
            ->allowElement('details', ['open'])
            ->allowAttribute('align', ['th', 'td', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'])
            ->allowAttribute('class', '*')
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->forceAttribute('a', 'rel', 'nofollow noindex noopener external ugc')
            ->withAttributeSanitizer(new ReadmeLinkSanitizer($host, $owner.'/'.$repo, $basePath))
            ->withAttributeSanitizer(new ReadmeImageSanitizer($host, $owner.'/'.$repo, $basePath))
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->withMaxInputLength(10_000_000);

        $sanitizer = new HtmlSanitizer($config);
        $readme = $sanitizer->sanitizeFor('body', $readme);

        // remove first page element if it's a <h1> or <h2>, because it's usually
        // the project name or the `README` string which we don't need
        $readme = Preg::replace('{^<(h[12])[^>]*>.*</(?1)>}', '', $readme);

        return str_replace("\r\n", "\n", $readme);
    }

    /**
     * @phpstan-param string|null $str
     *
     * @phpstan-return ($str is string ? string : null)
     */
    private function sanitize(?string $str): ?string
    {
        if (null === $str) {
            return null;
        }

        // remove escape chars
        $str = Preg::replace("{\x1B(?:\[.)?}u", '', $str);

        return Preg::replace("{[\x01-\x1A]}u", '', $str);
    }

    /**
     * Package metadata link fields are rendered into href attributes, so only web
     * schemes are allowed, mirroring the readme link sanitizer (allowLinkSchemes).
     *
     * @param list<string> $allowedSchemes
     */
    private function filterUrl(?string $url, array $allowedSchemes = ['http', 'https']): ?string
    {
        if (null === $url || '' === $url) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));

        return \in_array($scheme, $allowedSchemes, true) ? $url : null;
    }

    /**
     * @param array{issues?: string, forum?: string, wiki?: string, source?: string, email?: string, irc?: string, docs?: string, rss?: string, chat?: string, security?: string}|null $support
     *
     * @return array<string, string>|null
     */
    private function filterSupportUrls(?array $support): ?array
    {
        if (null === $support) {
            return null;
        }

        foreach (['issues', 'forum', 'wiki', 'source', 'docs', 'rss', 'security'] as $key) {
            if (isset($support[$key]) && null === $this->filterUrl($support[$key])) {
                unset($support[$key]);
            }
        }

        // chat and irc may point at an IRC channel, so allow the irc/ircs schemes there too
        foreach (['chat', 'irc'] as $key) {
            if (isset($support[$key]) && null === $this->filterUrl($support[$key], ['http', 'https', 'irc', 'ircs'])) {
                unset($support[$key]);
            }
        }

        return $support;
    }

    /**
     * @param array<array{type?: string, url?: string}>|null $funding
     *
     * @return array<array{type?: string, url?: string}>|null
     */
    private function filterFundingUrls(?array $funding): ?array
    {
        if (null === $funding) {
            return null;
        }

        foreach ($funding as $i => $entry) {
            if (isset($entry['url']) && null === $this->filterUrl($entry['url'])) {
                unset($funding[$i]['url']);
            }
        }

        return $funding;
    }

    private function detectAbandonmentReason(VcsDriverInterface $driver, string $rootIdentifier): AbandonmentReason
    {
        $isArchived = false;
        $composerHasAbandoned = false;

        // is repository archived (GitHub or GitLab)
        if ($driver instanceof GitHubDriver || $driver instanceof GitLabDriver) {
            try {
                $repoData = $driver->getRepoData();
                $isArchived = !empty($repoData['archived']);
            } catch (\Exception $e) {
                // If we can't get repo data, assume not archived
            }
        }

        // is abandoned field in composer.json explicitly set
        try {
            $composerJson = $driver->getFileContent('composer.json', $rootIdentifier);
            if ($composerJson) {
                $composerData = json_decode($composerJson, true);
                $composerHasAbandoned = isset($composerData['abandoned']);
            }
        } catch (\Exception $e) {
            // composer.json couldn't be read, so the abandoned state couldn't be retrieved
        }

        return match (true) {
            $isArchived && $composerHasAbandoned => AbandonmentReason::RepositoryArchivedAndComposerJson,
            $isArchived => AbandonmentReason::RepositoryArchived,
            $composerHasAbandoned => AbandonmentReason::ComposerJson,
            default => AbandonmentReason::Unknown,
        };
    }
}
