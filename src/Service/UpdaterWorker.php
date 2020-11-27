<?php declare(strict_types=1);

namespace App\Service;

use App\SecurityAdvisory\FriendsOfPhpSecurityAdvisoriesSource;
use Psr\Log\LoggerInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\ValidatingArrayLoader;
use Doctrine\Persistence\ManagerRegistry;
use Composer\Console\HtmlOutputFormatter;
use Composer\Repository\InvalidRepositoryException;
use Composer\Repository\VcsRepository;
use Composer\IO\BufferIO;
use Symfony\Component\Console\Output\OutputInterface;
use App\Entity\Package;
use App\Entity\Version;
use App\Package\Updater;
use App\Entity\Job;
use App\Entity\EmptyReferenceCache;
use App\Model\PackageManager;
use App\Model\DownloadManager;
use Seld\Signal\SignalHandler;
use Composer\Factory;
use Composer\Downloader\TransportException;
use Composer\Util\HttpDownloader;

class UpdaterWorker
{
    private $logger;
    private $doctrine;
    private $updater;
    private $locker;
    /** @var Scheduler */
    private $scheduler;
    private $packageManager;
    private $downloadManager;

    public function __construct(
        LoggerInterface $logger,
        ManagerRegistry $doctrine,
        Updater $updater,
        Locker $locker,
        Scheduler $scheduler,
        PackageManager $packageManager,
        DownloadManager $downloadManager
    ) {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->updater = $updater;
        $this->locker = $locker;
        $this->scheduler = $scheduler;
        $this->packageManager = $packageManager;
        $this->downloadManager = $downloadManager;
    }

    public function process(Job $job, SignalHandler $signal): array
    {
        $em = $this->doctrine->getManager();
        $id = $job->getPayload()['id'];
        $packageRepository = $em->getRepository(Package::class);
        /** @var Package $package */
        $package = $packageRepository->findOneById($id);
        if (!$package) {
            $this->logger->info('Package is gone, skipping', ['id' => $id]);

            return ['status' => Job::STATUS_PACKAGE_GONE, 'message' => 'Package was deleted, skipped'];
        }

        $packageName = $package->getName();
        $packageVendor = $package->getVendor();

        $lockAcquired = $this->locker->lockPackageUpdate($id);
        if (!$lockAcquired) {
            return ['status' => Job::STATUS_RESCHEDULE, 'after' => new \DateTime('+5 seconds'), 'vendor' => $packageVendor];
        }

        $this->logger->info('Updating '.$packageName);

        $config = Factory::createConfig();
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE, new HtmlOutputFormatter(Factory::createAdditionalStyles()));
        $io->loadConfiguration($config);

        $httpDownloader = new HttpDownloader($io, $config);

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
            if (($job->getPayload()['force_dump'] ?? false) === true) {
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
                $em->flush($emptyRefCache);
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
                null,
                $versionCache
            );
            $repository->setLoader($loader);

            // perform the actual update (fetch and re-scan the repository's source)
            $package = $this->updater->update($io, $config, $package, $repository, $flags, $existingVersions, $versionCache);

            $emptyRefCache = $em->merge($emptyRefCache);
            $emptyRefCache->setEmptyReferences($repository->getEmptyReferences());
            $em->flush($emptyRefCache);

            // github update downgraded to a git clone, this should not happen, so check through API whether the package still exists
            if (preg_match('{[@/]github.com[:/]([^/]+/[^/]+?)(\.git)?$}i', $package->getRepository(), $match) && 0 === strpos($repository->getDriver()->getUrl(), 'git@')) {
                if ($result = $this->checkForDeadGitHubPackage($package, $match, $httpDownloader, $io->getOutput())) {
                    return $result;
                }
            }
        } catch (\Throwable $e) {
            $output = $io->getOutput();

            if (!$this->doctrine->getManager()->isOpen()) {
                $this->doctrine->resetManager();
                $package = $this->doctrine->getManager()->getRepository(Package::class)->findOneById($package->getId());
            } else {
                // reload the package just in case as Updater tends to merge it to a new instance
                $package = $packageRepository->findOneById($id);
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
            } elseif ($e instanceof \RuntimeException && strpos($e->getMessage(), 'git@bitbucket.org') && strpos($e->getMessage(), 'Please make sure you have the correct access rights')) {
                // git clone says we have no right on bitbucket for 404s
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && strpos($e->getMessage(), '@github.com/') && strpos($e->getMessage(), ' Please ask the owner to check their account')) {
                // git clone says account is disabled on github for private repos(?) if cloning via https
                $found404 = true;
            } elseif ($e instanceof TransportException && preg_match('{https://api.bitbucket.org/2.0/repositories/[^/]+/.+?\?fields=-project}i', $e->getMessage()) && $e->getStatusCode() == 404) {
                // bitbucket api root returns a 404
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && preg_match('{fatal: repository \'[^\']+\' not found\n}i', $e->getMessage())) {
                // random git clone failures
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && (
                preg_match('{fatal: could not read Username for \'[^\']+\': No such device or address\n}i', $e->getMessage())
                || preg_match('{fatal: unable to access \'[^\']+\': Could not resolve host: }i', $e->getMessage())
                || preg_match('{Can\'t connect to host \'[^\']+\': Connection timed out}i', $e->getMessage())
            )) {
                // unreachable host, skip for a week as this may be a temporary failure
                $found404 = new \DateTime('+7 days');
            }

            // github 404'ed, check through API whether the package still exists and delete if not
            if ($found404 && preg_match('{[@/]github.com[:/]([^/]+/[^/]+?)(\.git)?$}i', $package->getRepository(), $match)) {
                if ($result = $this->checkForDeadGitHubPackage($package, $match, $httpDownloader, $output)) {
                    return $result;
                }
            }

            // detected a 404 so mark the package as gone and prevent updates for 1y
            if ($found404) {
                $package->setCrawledAt($found404 === true ? new \DateTime('+1 year') : $found404);
                $this->doctrine->getManager()->flush($package);

                return [
                    'status' => Job::STATUS_PACKAGE_GONE,
                    'message' => 'Update of '.$packageName.' failed, package appears to be 404/gone and has been marked as crawled for 1year',
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
            $this->scheduler->scheduleSecurityAdvisory(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME);
        }

        return [
            'status' => Job::STATUS_COMPLETED,
            'message' => 'Update of '.$packageName.' complete',
            'details' => '<pre>'.$this->cleanupOutput($io->getOutput()).'</pre>',
            'vendor' => $packageVendor,
        ];
    }

    private function cleanupOutput($str)
    {
        return preg_replace('{
            Reading\ composer.json\ of\ <span(.+?)>(?P<pkg>[^<]+)</span>\ \(<span(.+?)>(?P<version>[^<]+)</span>\)\r?\n
            (?P<cache>Found\ cached\ composer.json\ of\ <span(.+?)>(?P=pkg)</span>\ \(<span(.+?)>(?P=version)</span>\)\r?\n)
        }x', '$5', $str);
    }

    private function checkForDeadGitHubPackage(Package $package, $match, HttpDownloader $httpDownloader, $output)
    {
        try {
            $httpDownloader->get('https://api.github.com/repos/'.$match[1], ['retry-auth-failure' => false]);
        } catch (\Throwable $e) {
            if ($e instanceof TransportException && $e->getStatusCode() === 404) {
                try {
                    if (
                        // check composer repo is visible to make sure it's not github or something else glitching
                        $httpDownloader->get('https://api.github.com/repos/composer/composer', ['retry-auth-failure' => false])
                        // remove packages with very low downloads and that are 404
                        && $this->downloadManager->getTotalDownloads($package) <= 100
                    ) {
                        $this->packageManager->deletePackage($package);

                        return [
                            'status' => Job::STATUS_PACKAGE_DELETED,
                            'message' => 'Update of '.$package->getName().' failed, package appears to be 404/gone and has been deleted',
                            'details' => '<pre>'.$output.'</pre>',
                            'exception' => $e,
                            'vendor' => $package->getVendor()
                        ];
                    }
                } catch (\Throwable $e) {
                    // ignore failures here, we/github must be offline
                }
            }
        }
    }
}
