<?php declare(strict_types=1);

namespace App\Service;

use App\EventListener\SecurityAdvisoryUpdateListener;
use App\SecurityAdvisory\SecurityAdvisoryResolver;
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
    private const ADVISORY_WORKER_RUN = 'run';

    /**
     * @param SecurityAdvisorySourceInterface[] $sources
     */
    public function __construct(
        private Locker $locker,
        private LoggerInterface $logger,
        private ManagerRegistry $doctrine,
        private array $sources,
        private SecurityAdvisoryResolver $securityAdvisoryResolver,
        private SecurityAdvisoryUpdateListener $advisoryUpdateListener,
    ) {
    }

    /**
     * @return array{status: Job::STATUS_*, after?: \DateTime, message?: string, details?: string}
     */
    public function process(Job $job, SignalHandler $signal): array
    {
        $sourceName = $job->getPayload()['source'];

        $lockAcquired = $this->locker->lockSecurityAdvisory(self::ADVISORY_WORKER_RUN);
        if (!$lockAcquired) {
            return ['status' => Job::STATUS_RESCHEDULE, 'after' => new \DateTime('+2 minutes')];
        }

        $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE, new HtmlOutputFormatter(Factory::createAdditionalStyles()));

        $source = $this->sources[$sourceName];
        $remoteAdvisories = $source->getAdvisories($io);
        if (null === $remoteAdvisories) {
            $this->logger->info('Security advisory update failed, skipping', ['source' => $sourceName]);

            return ['status' => Job::STATUS_ERRORED, 'message' => 'Security advisory update failed, skipped'];
        }

        /** @var SecurityAdvisory[] $existingAdvisories */
        $existingAdvisories = $this->doctrine->getRepository(SecurityAdvisory::class)->getPackageAdvisoriesWithSources($remoteAdvisories->getPackageNames(), $sourceName);

        [$new, $removed] = $this->securityAdvisoryResolver->resolve($existingAdvisories, $remoteAdvisories, $sourceName);

        foreach ($new as $advisory) {
            $this->doctrine->getManager()->persist($advisory);
        }

        foreach ($removed as $advisory) {
            $this->doctrine->getManager()->remove($advisory);
        }

        $this->doctrine->getManager()->flush();

        $this->advisoryUpdateListener->flushChangesToPackages();

        $this->locker->unlockSecurityAdvisory(self::ADVISORY_WORKER_RUN);

        return [
            'status' => Job::STATUS_COMPLETED,
            'message' => 'Update of '.$sourceName.' security advisory complete',
            'details' => '<pre>'.$io->getOutput().'</pre>',
        ];
    }
}
