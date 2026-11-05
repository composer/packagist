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

use App\Entity\EmptyReferenceCache;
use App\Entity\Job;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\Version;
use App\Model\DownloadManager;
use App\Model\PackageManager;
use App\Package\HttpGitShadow;
use App\Package\UpdateFailureClassifier;
use App\Package\UpdateFailureKind;
use App\Package\UpdateFailureVerdict;
use App\Package\Updater;
use App\Package\VcsRepositoryFactory;
use App\SecurityAdvisory\FriendsOfPhpSecurityAdvisoriesSource;
use App\Util\DoctrineTrait;
use App\Util\LoggingHttpDownloader;
use Composer\Console\HtmlOutputFormatter;
use Composer\Downloader\TransportException;
use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Pcre\Preg;
use Composer\Repository\InvalidRepositoryException;
use Composer\Semver\Intervals;
use Composer\Util\HttpDownloader;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\Persistence\ManagerRegistry;
use Graze\DogStatsD\Client as StatsDClient;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Seld\Signal\SignalHandler;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class UpdaterWorker
{
    use DoctrineTrait;

    private const string GONE_AFTER_UNREACHABLE_FOR = '4 weeks';

    private LoggerInterface $logger;
    private ManagerRegistry $doctrine;
    private Updater $updater;
    private Locker $locker;
    private Scheduler $scheduler;
    private PackageManager $packageManager;
    private DownloadManager $downloadManager;
    private CacheInterface $cache;
    /** For use in fixtures loader only */
    private bool $loadMinimalVersions = false;

    public function __construct(
        LoggerInterface $logger,
        ManagerRegistry $doctrine,
        Updater $updater,
        Locker $locker,
        Scheduler $scheduler,
        PackageManager $packageManager,
        DownloadManager $downloadManager,
        private StatsDClient $statsd,
        private readonly FallbackGitHubAuthProvider $fallbackGitHubAuthProvider,
        CacheItemPoolInterface $cache,
        private readonly string $updaterWorkerCacheDir,
        private readonly HttpGitShadow $httpGitShadow,
    ) {
        $this->cache = new Psr16Cache($cache);
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->updater = $updater;
        $this->locker = $locker;
        $this->scheduler = $scheduler;
        $this->packageManager = $packageManager;
        $this->downloadManager = $downloadManager;
    }

    /**
     * @internal for fixtures usage only
     */
    public function setLoadMinimalVersions(bool $loadMinimalVersions): void
    {
        $this->loadMinimalVersions = $loadMinimalVersions;
    }

    /**
     * @param Job<PackageUpdateJob> $job
     *
     * @return array{status: Job::STATUS_*, message?: string, after?: \DateTimeImmutable, vendor?: string, details?: string, exception?: \Throwable}
     *
     * @phpstan-return PackageCompletedResult|PackageFailedResult|PackageGoneResult|PackageDeletedResult|RescheduleResult
     */
    public function process(Job $job, SignalHandler $signal): array
    {
        $em = $this->getEM();
        $id = $job->getPayload()['id'];
        $packageRepository = $em->getRepository(Package::class);
        $package = $packageRepository->find($id);
        if (!$package) {
            $this->logger->info('Package is gone, skipping', ['id' => $id]);

            return ['status' => Job::STATUS_PACKAGE_GONE, 'message' => 'Package was deleted, skipped', 'vendor' => 'unknown', 'exception' => EntityNotFoundException::fromClassNameAndIdentifier(Package::class, ['id' => (string) $id])];
        }

        $packageName = $package->getName();
        $packageVendor = $package->getVendor();

        $lockAcquired = $this->locker->lockPackageUpdate($id);
        if (!$lockAcquired) {
            return ['status' => Job::STATUS_RESCHEDULE, 'after' => new \DateTimeImmutable('+5 seconds'), 'vendor' => $packageVendor, 'message' => 'Could not acquire lock'];
        }

        $this->logger->info('Updating '.$packageName);

        $config = VcsRepositoryFactory::createConfig();
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE, new HtmlOutputFormatter(Factory::createAdditionalStyles()));
        $io->loadConfiguration($config);
        $io->enableTimestamps();

        // sandbox into a unique cache dir per package id to avoid potential cache reuse issues
        if (trim($this->updaterWorkerCacheDir) !== '' && is_dir($this->updaterWorkerCacheDir)) {
            $subDir = str_pad((string) $package->getId(), 9, '0', \STR_PAD_LEFT);
            $subDir = substr($subDir, 0, 6).'/'.$package->getId();
            $config->merge(['config' => ['cache-dir' => $this->updaterWorkerCacheDir.'/'.$subDir]]);
            unset($subDir);
        }

        $usesPackagistToken = false;
        if (Preg::isMatch('{^https://github\.com/(?P<repo>[^/]+/[^/]+?)(?:\.git)?$}i', $package->getRepository(), $matches)) {
            $usesPackagistToken = true;

            foreach ($package->getMaintainers() as $maintainer) {
                if ($maintainer->getId() === 1) {
                    continue;
                }
                if (!($newGithubToken = $maintainer->getGithubToken())) {
                    continue;
                }

                $valid = $this->cache->get('is_token_valid_'.$maintainer->getUsernameCanonical());
                if ('1' !== $valid) {
                    $context = stream_context_create(['http' => [
                        'header' => ['User-agent: packagist-token-check', 'Authorization: token '.$newGithubToken],
                        'ignore_errors' => true,
                    ]]);
                    $rateResponse = json_decode((string) @file_get_contents('https://api.github.com/repos/'.$matches['repo'].'/git/refs/heads?per_page=1', false, $context), true);
                    // invalid/outdated token, wipe it so we don't try it again
                    if (!$rateResponse || (isset($http_response_header[0]) && Preg::isMatch('{HTTP/\s+ 4[0-9][0-9] }', $http_response_header[0]))) {
                        if (str_contains($http_response_header[0], '403') || str_contains($http_response_header[0], '401')) {
                            $this->logger->error('Invalid token check response for '.$maintainer->getUsernameCanonical().' on '.$matches['repo'], ['headers' => $http_response_header, 'response' => $rateResponse]);
                            $maintainer->setGithubToken(null);
                            $em->persist($maintainer);
                            $em->flush();
                            continue;
                        }
                    }
                }

                $this->cache->set('is_token_valid_'.$maintainer->getUsernameCanonical(), '1', 86400);

                $usesPackagistToken = false;
                $io->setAuthentication('github.com', $newGithubToken, 'x-oauth-basic');
                break;
            }
        }

        if ($usesPackagistToken) {
            $fallbackToken = $this->fallbackGitHubAuthProvider->getAuthToken();
            if (null !== $fallbackToken) {
                $io->setAuthentication('github.com', $fallbackToken, 'x-oauth-basic');
            }
        }

        $httpDownloader = new LoggingHttpDownloader($io, $config, $this->statsd, $usesPackagistToken, $packageVendor, $this->fallbackGitHubAuthProvider->getAuthToken(...));
        if ($this->loadMinimalVersions) {
            $httpDownloader->loadMinimalVersions();
        }

        try {
            $flags = 0;
            $useVersionCache = true;
            if (($job->getPayload()['update_source_dist_url'] ?? false) === true) {
                $flags |= Updater::UPDATE_SOURCE_DIST_URL;
                $useVersionCache = false;
            }
            if ($job->getPayload()['delete_before'] === true) {
                $flags |= Updater::DELETE_BEFORE;
                $useVersionCache = false;
            }
            if ($job->getPayload()['force_dump'] === true) {
                $flags |= Updater::FORCE_DUMP;
            }

            // prepare dependencies
            $loader = new ValidatingArrayLoader(new ArrayLoader());

            $versionCache = null;
            $existingVersions = null;
            $emptyRefCache = $em->getRepository(EmptyReferenceCache::class)->findOneBy(['package' => $package]);
            if (!$emptyRefCache) {
                $emptyRefCache = new EmptyReferenceCache($package);
                $em->persist($emptyRefCache);
                $em->flush();
            }

            if ($useVersionCache) {
                $existingVersions = $em->getRepository(Version::class)->getVersionMetadataForUpdate($package);

                $versionCache = new VersionCache($package, $existingVersions, $emptyRefCache->getEmptyReferences());
            } else {
                $emptyRefCache->setEmptyReferences([]);
            }

            // prepare repository
            $repository = VcsRepositoryFactory::create($package->getRepository(), $io, $config, $httpDownloader, $versionCache, ['retry-auth-failure' => false]);
            $repository->setLoader($loader);

            // perform the actual update (fetch and re-scan the repository's source)
            $package = $this->updater->update($io, $config, $httpDownloader, $package, $repository, $flags, $existingVersions, $versionCache);

            $emptyRefCache->setEmptyReferences($repository->getEmptyReferences());
            $em->persist($emptyRefCache);
            $em->flush();

            $driver = $repository->getDriver();
            if ($driver) {
                $this->httpGitShadow->compareWithUpdate($package, $driver, $versionCache, $config, $httpDownloader);
            }
        } catch (\Throwable $e) {
            $e = VcsRepositoryFactory::unwrapDisabledGitFallback($e);
            $output = $this->cleanupOutput($io->getOutput());

            if (!$this->getEM()->isOpen()) {
                $this->doctrine->resetManager();
                $package = $this->getEM()->getRepository(Package::class)->find($package->getId());
            } else {
                // reload the package just in case as Updater tends to merge it to a new instance
                $package = $packageRepository->find($id);
            }

            if (!$package) {
                return [
                    'status' => Job::STATUS_FAILED,
                    'message' => 'Update of '.$packageName.' failed, package appears to be gone',
                    'details' => '<pre>'.$output.'</pre>',
                    'exception' => $e,
                    'vendor' => $packageVendor,
                ];
            }

            // invalid composer data somehow, notify the owner and then mark the job failed
            if ($e instanceof InvalidRepositoryException) {
                $this->packageManager->notifyUpdateFailure($package, $e, $output);

                return [
                    'status' => Job::STATUS_FAILED,
                    'message' => 'Update of '.$packageName.' failed, invalid composer.json metadata',
                    'details' => '<pre>'.$output.'</pre>',
                    'exception' => $e,
                    'vendor' => $packageVendor,
                ];
            }

            // attempt to detect a 404/dead repository
            // TODO check and delete those packages with crawledAt in the far future but updatedAt in the past in a second step/job if the repo is really unreachable
            // probably should check for download count and a few other metrics to avoid false positives and ask humans to check the others
            $verdict = UpdateFailureClassifier::classify($e);
            if ($verdict !== null) {
                $internalReason = UpdateFailureClassifier::describe($verdict, $e);

                // github 404'ed, check through API whether the package still exists and delete if not
                if (Preg::isMatchStrictGroups('{[@/]github.com[:/]([^/]+/[^/]+?)(?:\.git)?$}i', $package->getRepository(), $match)) {
                    if ($result = $this->checkForDeadGitHubPackage($package, $match[1], $httpDownloader, $output, $internalReason)) {
                        return $result;
                    }
                }

                // gitlab 404'ed, check through API whether the package still exists and delete if not
                if (Preg::isMatchStrictGroups('{https://gitlab.com/([^/]+/.+)$}i', $package->getRepository(), $match)) {
                    if ($result = $this->checkForDeadGitLabPackage($package, $match[1], $httpDownloader, $output, $internalReason)) {
                        return $result;
                    }
                }

                if ($verdict->escalatesToGone) {
                    $now = new \DateTimeImmutable();
                    $package->unreachableSince ??= $now;
                    if ($package->unreachableSince <= $now->modify('-'.self::GONE_AFTER_UNREACHABLE_FOR)) {
                        $internalReason .= ' (unreachable since '.$package->unreachableSince->format(\DATE_ATOM).')';
                        $verdict = new UpdateFailureVerdict(UpdateFailureKind::Gone, $verdict->code);
                    }
                }

                if ($verdict->kind === UpdateFailureKind::Gone) {
                    $package->freeze(PackageFreezeReason::Gone, $internalReason);
                    $this->getEM()->persist($package);
                    $this->getEM()->flush();

                    return [
                        'status' => Job::STATUS_PACKAGE_GONE,
                        'message' => 'Update of '.$packageName.' failed, package appears to be 404/gone and has been marked frozen.',
                        'details' => '<pre>'.$output.'</pre>',
                        'exception' => $e,
                        'vendor' => $packageVendor,
                    ];
                }

                // unreachable host, skip for a week as this may be a temporary failure
                $package->setCrawledAt(new \DateTimeImmutable('+7 days'));
                $this->getEM()->persist($package);
                $this->getEM()->flush();

                return [
                    'status' => Job::STATUS_FAILED,
                    'message' => 'Package data of '.$packageName.' could not be downloaded. Could not reach remote VCS server. Please try again later.',
                    'details' => '<pre>'.$output.'</pre>',
                    'exception' => $e,
                    'vendor' => $packageVendor,
                ];
            }

            // Catch request timeouts e.g. gitlab.com
            if ($e instanceof TransportException && strpos($e->getMessage(), 'file could not be downloaded: failed to open stream: HTTP request failed!')) {
                $this->packageManager->recordFailedCrawl($package);

                return [
                    'status' => Job::STATUS_FAILED,
                    'message' => 'Package data of '.$packageName.' could not be downloaded. Could not reach remote VCS server. Please try again later.',
                    'exception' => $e,
                    'vendor' => $packageVendor,
                ];
            }

            // generic transport exception
            if ($e instanceof TransportException) {
                $this->packageManager->recordFailedCrawl($package);

                return [
                    'status' => Job::STATUS_FAILED,
                    'message' => 'Package data of '.$packageName.' could not be downloaded.',
                    'exception' => $e,
                    'vendor' => $packageVendor,
                ];
            }

            $this->logger->error('Failed update of '.$packageName, ['exception' => $e]);

            // unexpected error so mark the job errored
            throw $e;
        } finally {
            $this->locker->unlockPackageUpdate($id);
            Intervals::clear(); // ValidatingArrayLoader uses that so it is safer to clear to avoid caches leaking memory slowly
        }

        if ($packageName === FriendsOfPhpSecurityAdvisoriesSource::SECURITY_PACKAGE) {
            $this->scheduler->scheduleSecurityAdvisory(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME, $id);
        }

        return [
            'status' => Job::STATUS_COMPLETED,
            'message' => 'Update of '.$packageName.' complete',
            'details' => '<pre>'.$this->cleanupOutput($io->getOutput()).'</pre>',
            'vendor' => $packageVendor,
        ];
    }

    private function cleanupOutput(string $str): string
    {
        // remove "Reading composer.json of ..." lines preceding "Found cached composer.json..." ones as they are redundant
        $str = Preg::replace('{
            Reading\ composer.json\ of\ <span(.+?)>(?P<pkg>[^<]+)</span>\ \(<span(.+?)>(?P<version>[^<]+)</span>\)\r?\n
            (?P<cache>Found\ cached\ composer.json\ of\ <span(.+?)>(?P=pkg)</span>\ \(<span(.+?)>(?P=version)</span>\)\r?\n)
        }x', '$5', $str);

        $config = new HtmlSanitizerConfig()
            ->allowElement('span')
            ->allowAttribute('style', ['span'])
            ->withMaxInputLength(10_000_000);
        $sanitizer = new HtmlSanitizer($config);

        return $sanitizer->sanitize($str);
    }

    /**
     * @return PackageGoneResult|PackageDeletedResult|null
     */
    private function checkForDeadGitHubPackage(Package $package, string $repo, HttpDownloader $httpDownloader, string $output, string $internalReason): ?array
    {
        try {
            $httpDownloader->get('https://api.github.com/repos/'.$repo.'/git/refs/heads', ['retry-auth-failure' => false]);
        } catch (\Throwable $e) {
            // 404 indicates the repo does not exist
            // 409 indicates an empty repo which is about the same for our purposes
            // 451 is used for DMCA takedowns which also indicate the package is bust
            if (
                $e instanceof TransportException
                && (
                    \in_array($e->getStatusCode(), [404, 409, 451], true)
                    || ($e->getStatusCode() === 403 && str_contains((string) $e->getResponse(), 'Repository access blocked'))
                )
            ) {
                return $this->completeDeadPackageCheck('https://api.github.com/repos/composer/composer/git/refs/heads', $package, $httpDownloader, $output, $e, $internalReason);
            }
        }

        return null;
    }

    /**
     * @return PackageGoneResult|PackageDeletedResult|null
     */
    private function checkForDeadGitLabPackage(Package $package, string $repo, HttpDownloader $httpDownloader, string $output, string $internalReason): ?array
    {
        try {
            $httpDownloader->get('https://gitlab.com/api/v4/projects/'.urlencode($repo), ['retry-auth-failure' => false]);
        } catch (\Throwable $e) {
            // 404 indicates the repo does not exist
            if (
                $e instanceof TransportException
                    && \in_array($e->getStatusCode(), [404], true)
            ) {
                return $this->completeDeadPackageCheck('https://gitlab.com/api/v4/projects/'.urlencode('packagist/vcs-repository-test'), $package, $httpDownloader, $output, $e, $internalReason);
            }
        }

        return null;
    }

    /**
     * @return PackageGoneResult|PackageDeletedResult|null
     */
    private function completeDeadPackageCheck(string $referenceRepoApiUrl, Package $package, HttpDownloader $httpDownloader, string $output, TransportException $e, string $internalReason): ?array
    {
        try {
            // check composer reference repo is visible to make sure it's not github/gitlab or something else in between glitching
            $httpDownloader->get($referenceRepoApiUrl, ['retry-auth-failure' => false]);
        } catch (\Throwable) {
            // ignore failures here, we/github/gitlab/.. must be offline
            return null;
        }

        $internalReason .= ' (confirmed by the API: HTTP '.$e->getStatusCode().')';

        // remove packages with very low downloads and that are 404
        if ($this->downloadManager->getTotalDownloads($package) <= 100) {
            $this->packageManager->deletePackage($package, internalReason: $internalReason);

            return [
                'status' => Job::STATUS_PACKAGE_DELETED,
                'message' => 'Update of '.$package->getName().' failed, package appears to be 404/gone and has been deleted.',
                'details' => '<pre>'.$output.'</pre>',
                'exception' => $e,
                'vendor' => $package->getVendor(),
            ];
        }

        $package->freeze(PackageFreezeReason::Gone, $internalReason);
        $this->getEM()->persist($package);
        $this->getEM()->flush();

        return [
            'status' => Job::STATUS_PACKAGE_GONE,
            'message' => 'Update of '.$package->getName().' failed, package appears to be 404/gone and has been marked frozen.',
            'details' => '<pre>'.$output.'</pre>',
            'exception' => $e,
            'vendor' => $package->getVendor(),
        ];
    }
}
