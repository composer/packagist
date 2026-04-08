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
use App\Entity\Job;
use App\Entity\Package;
use App\FilterList\FilterListEntryUpdateListener;
use App\FilterList\FilterListResolver;
use App\FilterList\FilterLists;
use App\FilterList\List\FilterListInterface;
use App\Model\DownloadManager;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Seld\Signal\SignalHandler;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
        private MailerInterface $mailer,
        private DownloadManager $downloadManager,
        private string $mailFromEmail,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param Job<FilterListJob> $job
     *
     * @return FilterListCompletedResult|FilterListErroredResult|RescheduleResult
     */
    public function process(Job $job, SignalHandler $signal): array
    {
        $list = FilterLists::from($job->getPayload()['list']);

        $lockAcquired = $this->locker->lockFilterList(self::FILTER_LIST_WORKER_RUN);
        if (!$lockAcquired) {
            return ['status' => Job::STATUS_RESCHEDULE, 'after' => new \DateTimeImmutable('+2 minutes'), 'message' => 'Could not acquire lock'];
        }

        $source = $this->filterLists[$list->value];
        $remoteEntries = $source->getListEntries();
        if (null === $remoteEntries) {
            $this->logger->info('Filter list update failed, skipping', ['list' => $list]);
            $this->locker->unlockFilterList(self::FILTER_LIST_WORKER_RUN);

            return ['status' => Job::STATUS_ERRORED, 'message' => 'Filter list update failed, skipped'];
        }

        /** @var FilterListEntry[] $existingEntries */
        $existingEntries = $this->doctrine->getRepository(FilterListEntry::class)->getEntriesInList($list);
        [$new, $removed, $modifiedExisting] = $this->malwareFeedResolver->resolve($existingEntries, $remoteEntries);

        foreach ($new as $entry) {
            $this->doctrine->getManager()->persist($entry);
        }

        foreach ($removed as $entry) {
            $this->doctrine->getManager()->remove($entry);
        }

        if ($new !== [] || $removed !== [] || $modifiedExisting) {
            $this->doctrine->getManager()->flush();
        }

        $this->malwarePackageVersionUpdateListener->flushChangesToPackages();

        /** @var array<string, list<FilterListEntry>> $newEntriesByPackage */
        $newEntriesByPackage = [];
        foreach ($new as $entry) {
            $newEntriesByPackage[$entry->getPackageName()][] = $entry;
        }

        foreach ($newEntriesByPackage as $packageName => $entries) {
            $package = $this->doctrine->getRepository(Package::class)->findOneBy(['name' => $packageName]);
            $downloads = $package ? $this->downloadManager->getTotalDownloads($package->getId()) : 0;
            $packageUrl = $this->urlGenerator->generate('view_package', ['name' => $packageName], UrlGeneratorInterface::ABSOLUTE_URL);

            if ($downloads >= 10_000) {
                $subject = '[URGENT] Filter list entry added for high-download package '.$packageName.' ('.number_format($downloads).' downloads)';
                $body = 'A new filter list entry has been added for '.$packageName.' which has '.number_format($downloads)." total downloads. This requires urgent attention.\n\n";
            } else {
                $subject = 'Filter list entry added for '.$packageName;
                $body = 'A new filter list entry has been added for '.$packageName.".\n\n";
            }

            $body .= 'Package: '.$packageUrl."\n"
                .'List: '.$list->value."\n"
                .'Versions: '.implode(', ', array_map(fn (FilterListEntry $e) => $e->getVersion(), $entries))."\n"
                .'Reason: '.($entries[0]->getReason() ?? 'N/A')."\n"
                .'Link: '.($entries[0]->getLink() ?? 'N/A')."\n";

            $message = new Email()
                ->subject($subject)
                ->from(new Address($this->mailFromEmail))
                ->to($this->mailFromEmail)
                ->text($body)
            ;
            $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');
            $this->mailer->send($message);
        }

        $this->locker->unlockFilterList(self::FILTER_LIST_WORKER_RUN);

        return [
            'status' => Job::STATUS_COMPLETED,
            'message' => 'Update of fliter list '.$list->value.' complete',
        ];
    }
}
