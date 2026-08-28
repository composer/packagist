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

use App\Entity\Job;
use App\Entity\SecurityAdvisory;
use App\EventListener\SecurityAdvisoryUpdateListener;
use App\SecurityAdvisory\SecurityAdvisoryResolver;
use App\SecurityAdvisory\SecurityAdvisorySourceInterface;
use Composer\Console\HtmlOutputFormatter;
use Composer\Factory;
use Composer\IO\BufferIO;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Seld\Signal\SignalHandler;
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
     * @param Job<SecurityAdvisoryJob> $job
     *
     * @return AdvisoriesCompletedResult|AdvisoriesErroredResult|RescheduleResult
     */
    public function process(Job $job, SignalHandler $signal): array
    {
        $sourceName = $job->getPayload()['source'];

        $lockAcquired = $this->locker->lockSecurityAdvisory(self::ADVISORY_WORKER_RUN);
        if (!$lockAcquired) {
            return ['status' => Job::STATUS_RESCHEDULE, 'after' => new \DateTimeImmutable('+2 minutes'), 'message' => 'Could not acquire lock'];
        }

        $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE, new HtmlOutputFormatter(Factory::createAdditionalStyles()));

        $source = $this->sources[$sourceName];
        $remoteAdvisories = $source->getAdvisories($io);
        if (null === $remoteAdvisories) {
            $this->logger->info('Security advisory update failed, skipping', ['source' => $sourceName]);

            return ['status' => Job::STATUS_ERRORED, 'message' => 'Security advisory update failed, skipped'];
        }

        // Include packages that only have withdrawn advisories this run so their stale DB entries
        // are still loaded (getPackageAdvisoriesWithSources returns nothing for an empty list).
        $packageNames = array_values(array_unique([...$remoteAdvisories->getPackageNames(), ...$remoteAdvisories->getWithdrawnPackageNames()]));

        /** @var SecurityAdvisory[] $existingAdvisories */
        $existingAdvisories = $this->doctrine->getRepository(SecurityAdvisory::class)->getPackageAdvisoriesWithSources($packageNames, $sourceName);

        // Withdrawals must be committed on their own before any advisory reuses a freed
        // (packageName, cve) key. A withdrawal nulls the activeCve generated column backing
        // package_name_cve_idx, and Doctrine does not order the INSERT/UPDATE statements within a
        // single flush to keep that unique index transiently consistent, so a replacement advisory
        // reusing the same CVE in the same run would otherwise trip an unrecoverable constraint
        // violation. This happens twice: advisories withdrawn at the source, then advisories the
        // source simply stopped listing.
        [$existingAdvisories, $withdrawn] = $this->securityAdvisoryResolver->removeWithdrawn($existingAdvisories, $remoteAdvisories, $sourceName);
        if (\count($withdrawn) > 0) {
            $this->doctrine->getManager()->flush();
        }

        $plan = $this->securityAdvisoryResolver->planResolve($existingAdvisories, $remoteAdvisories, $sourceName);

        if (\count($this->securityAdvisoryResolver->applyWithdrawals($plan)) > 0) {
            $this->doctrine->getManager()->flush();
        }

        $new = $this->securityAdvisoryResolver->applyMatches($plan);

        foreach ($new as $advisory) {
            $this->doctrine->getManager()->persist($advisory);
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
