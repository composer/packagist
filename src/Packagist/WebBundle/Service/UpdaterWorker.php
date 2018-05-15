<?php declare(strict_types=1);

namespace Packagist\WebBundle\Service;

use Packagist\WebBundle\Service\Scheduler;
use Psr\Log\LoggerInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\ValidatingArrayLoader;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Composer\Console\HtmlOutputFormatter;
use Composer\Repository\InvalidRepositoryException;
use Composer\Repository\VcsRepository;
use Composer\IO\ConsoleIO;
use Composer\IO\BufferIO;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\HelperSet;
use Monolog\Handler\StreamHandler;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Package\Updater;
use Packagist\WebBundle\Entity\Job;
use Packagist\WebBundle\Model\PackageManager;
use Seld\Signal\SignalHandler;
use Composer\Factory;
use Composer\Downloader\TransportException;

class UpdaterWorker
{
    private $logger;
    private $doctrine;
    private $updater;
    private $locker;
    /** @var Scheduler */
    private $scheduler;
    private $packageManager;

    public function __construct(
        LoggerInterface $logger,
        RegistryInterface $doctrine,
        Updater $updater,
        Locker $locker,
        Scheduler $scheduler,
        PackageManager $packageManager
    ) {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->updater = $updater;
        $this->locker = $locker;
        $this->scheduler = $scheduler;
        $this->packageManager = $packageManager;
    }

    public function process(Job $job, SignalHandler $signal): array
    {
        $em = $this->doctrine->getEntityManager();
        $id = $job->getPayload()['id'];
        $packageRepository = $em->getRepository(Package::class);
        /** @var Package $package */
        $package = $packageRepository->findOneById($id);
        if (!$package) {
            $this->logger->info('Package is gone, skipping', ['id' => $id]);

            return ['status' => Job::STATUS_PACKAGE_GONE, 'message' => 'Package was deleted, skipped'];
        }

        $lockAcquired = $this->locker->lockPackageUpdate($id);
        if (!$lockAcquired) {
            return ['status' => Job::STATUS_RESCHEDULE, 'after' => new \DateTime('+5 seconds')];
        }

        $this->logger->info('Updating '.$package->getName());

        $config = Factory::createConfig();
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE, new HtmlOutputFormatter(Factory::createAdditionalStyles()));
        $io->loadConfiguration($config);

        try {
            $flags = 0;
            if ($job->getPayload()['update_equal_refs'] === true) {
                $flags = Updater::UPDATE_EQUAL_REFS;
            }
            if ($job->getPayload()['delete_before'] === true) {
                $flags = Updater::DELETE_BEFORE;
            }

            // prepare dependencies
            $loader = new ValidatingArrayLoader(new ArrayLoader());

            // prepare repository
            $repository = new VcsRepository(array('url' => $package->getRepository()), $io, $config);
            $repository->setLoader($loader);

            // perform the actual update (fetch and re-scan the repository's source)
            $this->updater->update($io, $config, $package, $repository, $flags);
        } catch (\Throwable $e) {
            $output = $io->getOutput();

            if (!$this->doctrine->getEntityManager()->isOpen()) {
                $this->doctrine->resetManager();
                $package = $this->doctrine->getEntityManager()->getRepository(Package::class)->findOneById($package->getId());
            }

            // invalid composer data somehow, notify the owner and then mark the job failed
            if ($e instanceof InvalidRepositoryException) {
                $this->packageManager->notifyUpdateFailure($package, $e, $output);

                return [
                    'status' => Job::STATUS_FAILED,
                    'message' => 'Update of '.$package->getName().' failed, invalid composer.json metadata',
                    'details' => '<pre>'.$output.'</pre>',
                    'exception' => $e,
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
            }

            // detected a 404 so mark the package as gone and prevent updates for 1y
            if ($found404) {
                $package->setCrawledAt(new \DateTime('+1 year'));
                $this->doctrine->getEntityManager()->flush($package);

                return [
                    'status' => Job::STATUS_PACKAGE_GONE,
                    'message' => 'Update of '.$package->getName().' failed, package appears to be 404/gone and has been marked as crawled for 1year',
                    'details' => '<pre>'.$output.'</pre>',
                    'exception' => $e,
                ];
            }

            // Catch request timeouts e.g. gitlab.com
            if ($e instanceof TransportException && strpos($e->getMessage(), 'file could not be downloaded: failed to open stream: HTTP request failed!')) {
                return [
                    'status' => Job::STATUS_FAILED,
                    'message' => 'Package data of '.$package->getName().' could not be downloaded. Could not reach remote VCS server. Please try again later.',
                    'exception' => $e
                ];
            }

            // generic transport exception
            if ($e instanceof TransportException) {
                return [
                    'status' => Job::STATUS_FAILED,
                    'message' => 'Package data of '.$package->getName().' could not be downloaded.',
                    'exception' => $e
                ];
            }

            $this->logger->error('Failed update of '.$package->getName(), ['exception' => $e]);

            // unexpected error so mark the job errored
            throw $e;
        } finally {
            $this->locker->unlockPackageUpdate($package->getId());
        }

        return [
            'status' => Job::STATUS_COMPLETED,
            'message' => 'Update of '.$package->getName().' complete',
            'details' => '<pre>'.$io->getOutput().'</pre>'
        ];
    }
}
