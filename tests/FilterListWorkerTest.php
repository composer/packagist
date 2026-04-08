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

namespace App\Tests;

use App\Entity\FilterListEntry;
use App\Entity\FilterListEntryRepository;
use App\Entity\Job;
use App\Entity\Package;
use App\Entity\PackageRepository;
use App\FilterList\FilterListEntryUpdateListener;
use App\FilterList\FilterListResolver;
use App\FilterList\FilterLists;
use App\FilterList\List\FilterListInterface;
use App\FilterList\RemoteFilterListEntry;
use App\Model\DownloadManager;
use App\Service\FilterListWorker;
use App\Service\Locker;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Seld\Signal\SignalHandler;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class FilterListWorkerTest extends TestCase
{
    private FilterListWorker $worker;
    private FilterListInterface&MockObject $filterList;
    private EntityManager&MockObject $em;
    private FilterListEntryRepository&MockObject $filterListEntryRepository;
    private PackageRepository $packageRepository;
    private Locker&MockObject $locker;
    private MailerInterface&MockObject $mailer;
    private DownloadManager $downloadManager;
    private UrlGeneratorInterface $urlGenerator;

    protected function setUp(): void
    {
        $this->filterList = $this->createMock(FilterListInterface::class);
        $this->locker = $this->createMock(Locker::class);
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->downloadManager = $this->createStub(DownloadManager::class);
        $this->urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $doctrine = $this->createStub(ManagerRegistry::class);
        $this->worker = new FilterListWorker($this->locker, new NullLogger(), $doctrine, [FilterLists::AIKIDO_MALWARE->value => $this->filterList], new FilterListResolver(), new FilterListEntryUpdateListener($doctrine), $this->mailer, $this->downloadManager, 'test@example.com', $this->urlGenerator);

        $this->em = $this->createMock(EntityManager::class);

        $doctrine
            ->method('getManager')
            ->willReturn($this->em);

        $this->em
            ->method('getConnection')
            ->willReturn($this->createStub(Connection::class));

        $this->filterListEntryRepository = $this->createMock(FilterListEntryRepository::class);
        $this->packageRepository = $this->createStub(PackageRepository::class);

        $doctrine
            ->method('getRepository')
            ->willReturnMap([
                [FilterListEntry::class, null, $this->filterListEntryRepository],
                [Package::class, null, $this->packageRepository],
            ]);
    }

    public function testProcess(): void
    {
        $remote1Existing = $this->createRemoteFilterListEntry('vendor/existing', '1.0.0');
        $remote2New = $this->createRemoteFilterListEntry('vendor/new-malware', '2.0.0');

        $existingEntry = new FilterListEntry($remote1Existing);
        $existingEntryToBeDeleted = new FilterListEntry($this->createRemoteFilterListEntry('vendor/removed', '3.0.0'));

        $this->expectLock();

        $this->filterList
            ->expects($this->once())
            ->method('getListEntries')
            ->willReturn([$remote1Existing, $remote2New]);

        $this->filterListEntryRepository
            ->expects($this->once())
            ->method('getEntriesInList')
            ->with(FilterLists::AIKIDO_MALWARE)
            ->willReturn([$existingEntry, $existingEntryToBeDeleted]);

        $this->em
            ->expects($this->atLeastOnce())
            ->method('persist');

        $this->em
            ->expects($this->once())
            ->method('remove')
            ->with($this->identicalTo($existingEntryToBeDeleted));

        $this->urlGenerator
            ->method('generate')
            ->willReturn('https://packagist.org/packages/vendor/new-malware');

        $this->mailer
            ->expects($this->once())
            ->method('send');

        $result = $this->worker->process($this->createJob(), SignalHandler::create());

        $this->assertSame(Job::STATUS_COMPLETED, $result['status']);
    }

    public function testProcessNoEntries(): void
    {
        $this->expectLock();

        $this->filterList
            ->expects($this->once())
            ->method('getListEntries')
            ->willReturn([]);

        $this->filterListEntryRepository
            ->expects($this->once())
            ->method('getEntriesInList')
            ->with(FilterLists::AIKIDO_MALWARE)
            ->willReturn([]);

        $this->expectNoPersistAndRemove();

        $result = $this->worker->process($this->createJob(), SignalHandler::create());

        $this->assertSame(Job::STATUS_COMPLETED, $result['status']);
    }

    public function testProcessFeedFailed(): void
    {
        $this->expectLock();

        $this->filterList
            ->expects($this->once())
            ->method('getListEntries')
            ->willReturn(null);

        $this->expectNoPersistAndRemove();

        $this->filterListEntryRepository
            ->expects($this->never())
            ->method('getEntriesInList');

        $result = $this->worker->process($this->createJob(), SignalHandler::create());

        $this->assertSame(Job::STATUS_ERRORED, $result['status']);
    }

    public function testProcessLockNotAcquired(): void
    {
        $this->locker
            ->expects($this->once())
            ->method('lockFilterList')
            ->willReturn(false);

        $this->filterList
            ->expects($this->never())
            ->method('getListEntries');

        $this->filterListEntryRepository
            ->expects($this->never())
            ->method('getEntriesInList');

        $this->expectNoPersistAndRemove();

        $result = $this->worker->process($this->createJob(), SignalHandler::create());

        $this->assertSame(Job::STATUS_RESCHEDULE, $result['status']);
        $this->assertArrayHasKey('after', $result);
    }

    private function createRemoteFilterListEntry(string $packageName, string $version): RemoteFilterListEntry
    {
        return new RemoteFilterListEntry(
            $packageName,
            $version,
            FilterLists::AIKIDO_MALWARE,
            'https://example.com/'.$packageName,
            'malware',
        );
    }

    private function createJob(): Job
    {
        return new Job('job', 'filter:update', ['list' => FilterLists::AIKIDO_MALWARE->value]);
    }

    private function expectLock(): void
    {
        $this->locker
            ->expects($this->once())
            ->method('lockFilterList')
            ->willReturn(true);

        $this->locker
            ->expects($this->once())
            ->method('unlockFilterList');
    }

    private function expectNoPersistAndRemove(): void
    {
        $this->em
            ->expects($this->never())
            ->method('persist');

        $this->em
            ->expects($this->never())
            ->method('remove');

        $this->em
            ->expects($this->never())
            ->method('flush');

        $this->mailer
            ->expects($this->never())
            ->method('send');
    }
}
