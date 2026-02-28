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

use App\Entity\FilterListEntry;
use App\FilterList\FilterLists;
use App\FilterList\List\FilterListInterface;
use App\FilterList\FilterListEntryUpdateListener;
use App\FilterList\FilterListResolver;
use App\Entity\Job;
use Psr\Log\LoggerInterface;
use Seld\Signal\SignalHandler;
use Doctrine\Persistence\ManagerRegistry;

final readonly class FilterListWorker
{
    private const string FILTER_LIST_WORKER_RUN = 'run';

    /**
     * @param FilterListInterface[] $filterLists
     */
    public function __construct(
        private Locker $locker,
        private LoggerInterface $logger,
        private ManagerRegistry $doctrine,
        private array $filterLists,
        private FilterListResolver $malwareFeedResolver,
        private FilterListEntryUpdateListener $malwarePackageVersionUpdateListener,
    ) {}

    /**
     * @param Job<FilterListJob> $job
     * @return FilterListCompletedResult|FilterListErroredResult|RescheduleResult
     */
    public function process(Job $job, SignalHandler $signal): array
    {
        $list = FilterLists::from($job->getPayload()['list']);

        $lockAcquired = $this->locker->lockFitlerList(self::FILTER_LIST_WORKER_RUN);
        if (!$lockAcquired) {
            return ['status' => Job::STATUS_RESCHEDULE, 'after' => new \DateTimeImmutable('+2 minutes'), 'message' => 'Could not acquire lock'];
        }

        $source = $this->filterLists[$list->value];
        $remoteMalwareFeed = $source->getListEntries();
        if (null === $remoteMalwareFeed) {
            $this->logger->info('Filter list update failed, skipping', ['list' => $list]);
            $this->locker->unlockFilterList(self::FILTER_LIST_WORKER_RUN);

            return ['status' => Job::STATUS_ERRORED, 'message' => 'Filter list update failed, skipped'];
        }

        /** @var FilterListEntry[] $existingMalwareFeedEntries */
        $existingMalwareFeedEntries = $this->doctrine->getRepository(FilterListEntry::class)->getPackageVersionsFlaggedAsMalwareInList($list);
        [$new, $removed] = $this->malwareFeedResolver->resolve($existingMalwareFeedEntries, $remoteMalwareFeed);

        foreach ($new as $entry) {
            $this->doctrine->getManager()->persist($entry);
        }

        foreach ($removed as $entry) {
            $this->doctrine->getManager()->remove($entry);
        }

        if ($new !== [] || $removed !== []) {
            $this->doctrine->getManager()->flush();
        }

        $this->malwarePackageVersionUpdateListener->flushChangesToPackages();

        $this->locker->unlockFilterList(self::FILTER_LIST_WORKER_RUN);

        return [
            'status' => Job::STATUS_COMPLETED,
            'message' => 'Update of fliter list '.$list->value.' complete',
        ];
    }
}
