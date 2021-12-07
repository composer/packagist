<?php declare(strict_types=1);

namespace App\Service;

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
        $lockAcquired = $this->locker->lockSecurityAdvisory($sourceName);
        if (!$lockAcquired) {
            return ['status' => Job::STATUS_RESCHEDULE, 'after' => new \DateTime('+5 minutes')];
        }

        $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE, new HtmlOutputFormatter(Factory::createAdditionalStyles()));

        /** @var SecurityAdvisorySourceInterface $source */
        $source = $this->sources[$sourceName];
        $remoteAdvisories = $source->getAdvisories($io);
        if (null === $remoteAdvisories) {
            $this->logger->info('Security advisory update failed, skipping', ['source' => $source]);

            return ['status' => Job::STATUS_ERRORED, 'message' => 'Security advisory update failed, skipped'];
        }

        /** @var SecurityAdvisory[] $existingAdvisoryMap */
        $existingAdvisoryMap = [];
        /** @var SecurityAdvisory[] $existingAdvisories */
        $existingAdvisories = $this->doctrine->getRepository(SecurityAdvisory::class)->findBy(['source' => $sourceName]);
        foreach ($existingAdvisories as $advisory) {
            // Assign an advisory id to all existing advisories -> remove once applied everywhere
            if (!$advisory->hasPackagistAdvisoryId()) {
                $advisory->assignPackagistAdvisoryId();
            }

            $existingAdvisoryMap[$advisory->getRemoteId()] = $advisory;
        }

        $unmatchedRemoteAdvisories = [];
        // Attempt to match existing advisories against remote id
        foreach ($remoteAdvisories as $remoteAdvisory) {
            if (isset($existingAdvisoryMap[$remoteAdvisory->getId()])) {
                $existingAdvisoryMap[$remoteAdvisory->getId()]->updateAdvisory($remoteAdvisory);
                unset($existingAdvisoryMap[$remoteAdvisory->getId()]);
            } else {
                $unmatchedRemoteAdvisories[$remoteAdvisory->getPackageName()][$remoteAdvisory->getId()] = $remoteAdvisory;
            }
        }

        // Try to match remaining remote advisories with remaining local advisories in case the remote id changed
        // Allow three changes e.g. filename, CVE, date on a rename
        $requiredDifferenceScore = 3;
        foreach ($existingAdvisoryMap as $existingAdvisory) {
            $matchedAdvisory = null;
            $lowestScore = 9999;
            if (isset($unmatchedRemoteAdvisories[$existingAdvisory->getPackageName()])) {
                foreach ($unmatchedRemoteAdvisories[$existingAdvisory->getPackageName()] as $unmatchedAdvisory) {
                    $score = $existingAdvisory->calculateDifferenceScore($unmatchedAdvisory);
                    if ($score < $lowestScore && $score <= $requiredDifferenceScore) {
                        $matchedAdvisory = $unmatchedAdvisory;
                        $lowestScore = $score;
                    }
                }
            }

            if ($matchedAdvisory === null) {
                $this->doctrine->getManager()->remove($existingAdvisory);
            } else {
                $existingAdvisory->updateAdvisory($matchedAdvisory);
                unset($unmatchedRemoteAdvisories[$matchedAdvisory->getPackageName()][$matchedAdvisory->getId()]);
            }
        }

        // No similar existing advisories found. Store them as new advisories
        foreach ($unmatchedRemoteAdvisories as $packageUnmatchedAdvisories) {
            foreach ($packageUnmatchedAdvisories as $unmatchedAdvisory) {
                $this->doctrine->getManager()->persist(new SecurityAdvisory($unmatchedAdvisory, $sourceName));
            }
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
