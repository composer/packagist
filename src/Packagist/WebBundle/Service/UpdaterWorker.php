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

            return ['status' => Job::STATUS_FAILED, 'message' => 'Package is gone, skipped'];
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

            $em->transactional(function($em) use ($package, $io, $config, $flags) {
                // prepare dependencies
                $loader = new ValidatingArrayLoader(new ArrayLoader());

                // prepare repository
                $repository = new VcsRepository(array('url' => $package->getRepository()), $io, $config);
                $repository->setLoader($loader);

                // perform the actual update (fetch and re-scan the repository's source)
                $this->updater->update($io, $config, $package, $repository, $flags);

                // update the package entity
                $package->setAutoUpdated(true);
                $em->flush($package);
            });
        } catch (\Composer\Downloader\TransportException $e) {
            // Catch request timeouts e.g. gitlab.com
            if (strpos($e->getMessage(), 'file could not be downloaded: failed to open stream: HTTP request failed!')) {
                return [
                    'status' => Job::STATUS_FAILED,
                    'message' => 'Package data could not be downloaded. Could not reach remote VCS server. Please try again later.',
                    'exception' => $e
                ];
            }

            return [
                'status' => Job::STATUS_FAILED,
                'message' => 'Package data could not be downloaded.',
                'exception' => $e
            ];
        } catch (InvalidRepositoryException $e) {
            if ($package->getRepoType() === 'package') {
                $this->logger->warning('Composer Invalid Repository Exception.', ['exception' => $e]);

                return [
                    'status' => Job::STATUS_FAILED,
                    'message' => explode("\n", $e->getMessage())[0],
                    'exception' => $e
                ];
            }

            throw $e;
        } catch (\Throwable $e) {
            if ($e instanceof InvalidRepositoryException) {
                $this->packageManager->notifyUpdateFailure($package, $e, $io->getOutput());
            } else {
                // TODO implement this somehow to keep track of failures and maybe delete packages
                // or at least mark abandoned if they keep failing for X times/days?
                // $this->packageManager->logHardFailure($package);
            }

            $this->logger->error('Failed update of '.$package->getName(), ['exception' => $e]);

            return [
                'status' => Job::STATUS_FAILED,
                'message' => 'Update failed',
                'details' => '<pre>'.$io->getOutput().'</pre>',
                'exception' => $e,
            ];
        } finally {
            $this->locker->unlockPackageUpdate($package->getId());
        }

        return [
            'status' => Job::STATUS_COMPLETED,
            'message' => 'Update complete',
            'details' => '<pre>'.$io->getOutput().'</pre>'
        ];
    }
}
