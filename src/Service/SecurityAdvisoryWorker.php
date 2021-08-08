<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Package;
use Composer\Console\HtmlOutputFormatter;
use Composer\Factory;
use Composer\IO\BufferIO;
use App\Entity\Job;
use App\Entity\SecurityAdvisory;
use App\SecurityAdvisory\SecurityAdvisorySourceInterface;
use Psr\Log\LoggerInterface;
use Seld\Signal\SignalHandler;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Output\OutputInterface;

class SecurityAdvisoryWorker
{
    private Locker $locker;

    private LoggerInterface $logger;

    private ManagerRegistry $doctrine;

    /** @var SecurityAdvisorySourceInterface[] */
    private array $sources;

    public function __construct(Locker $locker, LoggerInterface $logger, ManagerRegistry $doctrine, array $sources)
    {
        $this->locker = $locker;
        $this->sources = $sources;
        $this->logger = $logger;
        $this->doctrine = $doctrine;
    }

    public function process(Job $job, SignalHandler $signal): array
    {
        $sourceName = $job->getPayload()['source'];
        $package = $this->doctrine->getRepository(Package::class)->findOneBy(['id' => $job->getPackageId()]);

        if (!$package instanceof Package) {
            $this->logger->info('Security advisory update failed, skipping', ['packageId' => $job->getPackageId()]);

            return ['status' => Job::STATUS_ERRORED, 'message' => 'Security advisory update failed, skipped'];
        }

        $lockAcquired = $this->locker->lockSecurityAdvisory($sourceName);
        if (!$lockAcquired) {
            return ['status' => Job::STATUS_RESCHEDULE, 'after' => new \DateTime('+5 minutes')];
        }

        $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE, new HtmlOutputFormatter(Factory::createAdditionalStyles()));

        /** @var SecurityAdvisorySourceInterface $source */
        $source = $this->sources[$sourceName];
        $remoteAdvisories = $source->getAdvisories($io, $package);
        if (null === $remoteAdvisories) {
            $this->logger->info('Security advisory update failed, skipping', ['source' => $source]);

            return ['status' => Job::STATUS_ERRORED, 'message' => 'Security advisory update failed, skipped'];
        }

        /** @var SecurityAdvisory[] $existingAdvisoryMap */
        $existingAdvisoryMap = [];
        /** @var SecurityAdvisory[] $existingAdvisories */
        $existingAdvisories = $this->doctrine->getRepository(SecurityAdvisory::class)->findBy(['source' => $sourceName]);
        foreach ($existingAdvisories as $advisory) {
            $existingAdvisoryMap[$advisory->getRemoteId()] = $advisory;
        }

        foreach ($remoteAdvisories as $remoteAdvisory) {
            if (isset($existingAdvisoryMap[$remoteAdvisory->getId()])) {
                $existingAdvisoryMap[$remoteAdvisory->getId()]->updateAdvisory($remoteAdvisory);
                unset($existingAdvisoryMap[$remoteAdvisory->getId()]);
            } else {
                $this->doctrine->getManager()->persist(new SecurityAdvisory($remoteAdvisory, $sourceName));
            }
        }

        foreach ($existingAdvisoryMap as $advisory) {
            $this->doctrine->getManager()->remove($advisory);
        }

        $this->doctrine->getManager()->flush();

        $this->locker->unlockSecurityAdvisory($sourceName);

        return [
            'status' => Job::STATUS_COMPLETED,
            'message' => 'Update of '.$sourceName.' security advisory complete',
            'details' => '<pre>'.$io->getOutput().'</pre>',
        ];
    }
}
