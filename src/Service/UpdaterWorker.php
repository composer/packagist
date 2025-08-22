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
use App\Package\Updater;
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
use Composer\Repository\VcsRepository;
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

    public const VCS_REPO_DRIVERS = [
        'github' => 'Composer\Repository\Vcs\GitHubDriver',
        'gitlab' => 'Composer\Repository\Vcs\GitLabDriver',
        'git-bitbucket' => 'Composer\Repository\Vcs\GitBitbucketDriver',
        'git' => 'Composer\Repository\Vcs\GitDriver',
        'hg' => 'Composer\Repository\Vcs\HgDriver',
        'svn' => 'Composer\Repository\Vcs\SvnDriver',
    ];

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

        $config = Factory::createConfig();
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE, new HtmlOutputFormatter(Factory::createAdditionalStyles()));
        $io->loadConfiguration($config);

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

        $httpDownloader = new LoggingHttpDownloader($io, $config, $this->statsd, $usesPackagistToken, $packageVendor);
        if ($this->loadMinimalVersions) {
            $httpDownloader->loadMinimalVersions();
        }

        try {
            $flags = 0;
            $useVersionCache = true;
            if ($job->getPayload()['update_equal_refs'] === true) {
                $flags = Updater::UPDATE_EQUAL_REFS;
                $useVersionCache = false;
            }
            if ($job->getPayload()['delete_before'] === true) {
                $flags = Updater::DELETE_BEFORE;
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
            $repository = new VcsRepository(
                ['url' => $package->getRepository(), 'options' => ['retry-auth-failure' => false]],
                $io,
                $config,
                $httpDownloader,
                null,
                null,
                self::VCS_REPO_DRIVERS,
                $versionCache
            );
            $repository->setLoader($loader);

            // perform the actual update (fetch and re-scan the repository's source)
            $package = $this->updater->update($io, $config, $package, $repository, $flags, $existingVersions, $versionCache);

            $emptyRefCache->setEmptyReferences($repository->getEmptyReferences());
            $em->persist($emptyRefCache);
            $em->flush();

            // github update downgraded to a git clone, this should not happen, so check through API whether the package still exists
            $driver = $repository->getDriver();
            if ($driver && Preg::isMatchStrictGroups('{[@/]github.com[:/]([^/]+/[^/]+?)(?:\.git)?$}i', $package->getRepository(), $match) && str_starts_with($driver->getUrl(), 'git@')) {
                if ($result = $this->checkForDeadGitHubPackage($package, $match[1], $httpDownloader, $this->cleanupOutput($io->getOutput()))) {
                    return $result;
                }
            }
        } catch (\Throwable $e) {
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

            $found404 = false;

            // attempt to detect a 404/dead repository
            // TODO check and delete those packages with crawledAt in the far future but updatedAt in the past in a second step/job if the repo is really unreachable
            // probably should check for download count and a few other metrics to avoid false positives and ask humans to check the others
            if ($e instanceof \RuntimeException && strpos($e->getMessage(), 'remote: Repository not found')) {
                // git clone was attempted and says the repo is not found, that's very conclusive
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && strpos($e->getMessage(), 'git@gitlab.com') && strpos($e->getMessage(), 'Please make sure you have the correct access rights')) {
                // git clone says we have no right on gitlab for 404s
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && strpos($e->getMessage(), '@gitlab.com/') && strpos($e->getMessage(), 'You are not allowed to download code from this project')) {
                // project is gone on gitlab somehow
                $found404 = true;
            } elseif ($e instanceof TransportException && $e->getStatusCode() === 404 && strpos($e->getMessage(), 'https://gitlab.com/api/v4/projects/') && strpos($e->getMessage(), '404 Project Not Found')) {
                // http client 404s on gitlab
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && strpos($e->getMessage(), 'git@bitbucket.org') && strpos($e->getMessage(), 'Please make sure you have the correct access rights')) {
                // git clone says we have no right on bitbucket for 404s
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && strpos($e->getMessage(), '@github.com/') && strpos($e->getMessage(), ' Please ask the owner to check their account')) {
                // git clone says account is disabled on github for private repos(?) if cloning via https
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && strpos($e->getMessage(), '@github.com/') && strpos($e->getMessage(), ' Your account is suspended.')) {
                // git clone says account is suspended on github
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && strpos($e->getMessage(), '@github.com/') && strpos($e->getMessage(), 'Access to this repository has been disabled by GitHub staff due to excessive resource use.')) {
                // git clone says repo is disabled on github
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && str_contains($e->getMessage(), '@github.com/') && str_contains($e->getMessage(), 'remote: Write access to repository not granted.') && str_contains($e->getMessage(), 'The requested URL returned error: 403')) {
                // git clone failure on github with a 403 when the repo does not exist (or is private?)
                $found404 = true;
            } elseif ($e instanceof TransportException && Preg::isMatch('{https://api.bitbucket.org/2.0/repositories/[^/]+/.+?\?fields=-project}i', $e->getMessage()) && $e->getStatusCode() == 404) {
                // bitbucket api root returns a 404
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && Preg::isMatch('{fatal: repository \'[^\']+\' not found\n}i', $e->getMessage())) {
                // random git clone failures
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && Preg::isMatch('{fatal: Authentication failed}i', $e->getMessage())) {
                // git clone failed because repo now requires auth
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && Preg::isMatch('{Driver could not be established for package}i', $e->getMessage())) {
                // no driver found as it is a custom hosted git most likely on a server that is now unreachable or similar
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'svn: E160013:')) {
                // svn failed with a 404
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && (
                Preg::isMatch('{fatal: could not read Username for \'[^\']+\': No such device or address\n}i', $e->getMessage())
                || Preg::isMatch('{fatal: unable to access \'[^\']+\': Could not resolve host: }i', $e->getMessage())
                || Preg::isMatch('{fatal: unable to access \'[^\']+\': The requested URL returned error: 503}i', $e->getMessage())
                || Preg::isMatch('{fatal: unable to access \'[^\']+\': server certificate verification failed}i', $e->getMessage())
                || Preg::isMatch('{Can\'t connect to host \'[^\']+\': Connection (timed out|refused)}i', $e->getMessage())
                || Preg::isMatch('{Failed to connect to [\w.-]+ port \d+(?: after \d+ ms)?: Connection (timed out|refused)}i', $e->getMessage())
                || Preg::isMatch('{Failed to connect to [\w.-]+ port \d+(?: after \d+ ms)?: No route to host}i', $e->getMessage())
                || Preg::isMatch('{Failed to connect to [\w.-]+ port \d+(?: after \d+ ms)?: Couldn\'t connect to server}i', $e->getMessage())
                || Preg::isMatch('{SSL: certificate subject name \([\w.-]+\) does not match target host name \'[\w.-]+\'}i', $e->getMessage())
                || Preg::isMatch('{gnutls_handshake\(\) failed: The server name sent was not recognized}i', $e->getMessage())
                || Preg::isMatch('{svn: E170013: Unable to connect to a repository at URL}', $e->getMessage())
            )) {
                // unreachable host, skip for a week as this may be a temporary failure
                $found404 = new \DateTimeImmutable('+7 days');
            } elseif ($e instanceof TransportException && $e->getStatusCode() === 409 && Preg::isMatch('{^The "https://api\.github\.com/repos/[^/]+/[^/]+?/git/refs/heads\?per_page=100" file could not be downloaded \(HTTP/2 409 \)}', $e->getMessage())) {
                $found404 = true;
            } elseif ($e instanceof TransportException && $e->getStatusCode() === 451 && Preg::isMatch('{^The "https://api\.github\.com/repos/[^/]+/[^/]+?" file could not be downloaded \(HTTP/2 451 \)}', $e->getMessage())) {
                $found404 = true;
            }

            // github 404'ed, check through API whether the package still exists and delete if not
            if ($found404 && Preg::isMatchStrictGroups('{[@/]github.com[:/]([^/]+/[^/]+?)(?:\.git)?$}i', $package->getRepository(), $match)) {
                if ($result = $this->checkForDeadGitHubPackage($package, $match[1], $httpDownloader, $output)) {
                    return $result;
                }
            }

            // gitlab 404'ed, check through API whether the package still exists and delete if not
            if ($found404 && Preg::isMatchStrictGroups('{https://gitlab.com/([^/]+/.+)$}i', $package->getRepository(), $match)) {
                if ($result = $this->checkForDeadGitLabPackage($package, $match[1], $httpDownloader, $output)) {
                    return $result;
                }
            }

            // detected a 404 so prevent updates for x days or fully if the package is conclusively gone
            if ($found404) {
                if ($found404 === true) {
                    $package->freeze(PackageFreezeReason::Gone);
                } else {
                    $package->setCrawledAt($found404);
                }
                $this->getEM()->persist($package);
                $this->getEM()->flush();

                if ($found404 === true) {
                    return [
                        'status' => Job::STATUS_PACKAGE_GONE,
                        'message' => 'Update of '.$packageName.' failed, package appears to be 404/gone and has been marked frozen.',
                        'details' => '<pre>'.$output.'</pre>',
                        'exception' => $e,
                        'vendor' => $packageVendor,
                    ];
                }

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
                return [
                    'status' => Job::STATUS_FAILED,
                    'message' => 'Package data of '.$packageName.' could not be downloaded. Could not reach remote VCS server. Please try again later.',
                    'exception' => $e,
                    'vendor' => $packageVendor,
                ];
            }

            // generic transport exception
            if ($e instanceof TransportException) {
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
    private function checkForDeadGitHubPackage(Package $package, string $repo, HttpDownloader $httpDownloader, string $output): ?array
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
                    || ($e->getStatusCode() === 403 && str_contains('"message": "Repository access blocked"', (string) $e->getResponse()))
                )
            ) {
                return $this->completeDeadPackageCheck('https://api.github.com/repos/composer/composer/git/refs/heads', $package, $httpDownloader, $output, $e);
            }
        }

        return null;
    }

    /**
     * @return PackageGoneResult|PackageDeletedResult|null
     */
    private function checkForDeadGitLabPackage(Package $package, string $repo, HttpDownloader $httpDownloader, string $output): ?array
    {
        try {
            $httpDownloader->get('https://gitlab.com/api/v4/projects/'.urlencode($repo), ['retry-auth-failure' => false]);
        } catch (\Throwable $e) {
            // 404 indicates the repo does not exist
            if (
                $e instanceof TransportException
                    && \in_array($e->getStatusCode(), [404], true)
            ) {
                return $this->completeDeadPackageCheck('https://gitlab.com/api/v4/projects/'.urlencode('packagist/vcs-repository-test'), $package, $httpDownloader, $output, $e);
            }
        }

        return null;
    }

    /**
     * @return PackageGoneResult|PackageDeletedResult|null
     */
    private function completeDeadPackageCheck(string $referenceRepoApiUrl, Package $package, HttpDownloader $httpDownloader, string $output, TransportException $e): ?array
    {
        try {
            // check composer reference repo is visible to make sure it's not github/gitlab or something else in between glitching
            $httpDownloader->get($referenceRepoApiUrl, ['retry-auth-failure' => false]);
        } catch (\Throwable $e) {
            // ignore failures here, we/github/gitlab/.. must be offline
            return null;
        }

        // remove packages with very low downloads and that are 404
        if ($this->downloadManager->getTotalDownloads($package) <= 100) {
            $this->packageManager->deletePackage($package);

            return [
                'status' => Job::STATUS_PACKAGE_DELETED,
                'message' => 'Update of '.$package->getName().' failed, package appears to be 404/gone and has been deleted.',
                'details' => '<pre>'.$output.'</pre>',
                'exception' => $e,
                'vendor' => $package->getVendor(),
            ];
        }

        $package->freeze(PackageFreezeReason::Gone);
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
