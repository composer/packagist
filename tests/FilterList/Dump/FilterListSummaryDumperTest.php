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

namespace App\Tests\FilterList\Dump;

use App\Entity\FilterListEntryRepository;
use App\FilterList\Dump\FilterListDumperProvider;
use App\FilterList\Dump\FilterListSummaryDumper;
use App\FilterList\Dump\FilterListSummaryEntry;
use App\FilterList\FilterLists;
use App\Service\CdnClient;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class FilterListSummaryDumperTest extends TestCase
{
    private FilterListEntryRepository&MockObject $repository;
    private CdnClient&MockObject $cdn;
    private FilterListSummaryDumper $dumper;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(FilterListEntryRepository::class);

        $doctrine = $this->createStub(ManagerRegistry::class);
        $em = $this->createStub(EntityManager::class);
        $doctrine->method('getManager')->willReturn($em);
        $em->method('getRepository')->willReturn($this->repository);

        $provider = new FilterListDumperProvider($doctrine, $this->createStub(UrlGeneratorInterface::class));

        $this->cdn = $this->createMock(CdnClient::class);

        $this->dumper = new FilterListSummaryDumper(
            $provider,
            $this->repository,
            $this->cdn,
            new NullLogger(),
        );
    }

    public function testForceDumpAlwaysWritesAndPurges(): void
    {
        $this->repository
            ->expects($this->never())
            ->method('getNewestEntryUpdatedAt');

        $this->cdn
            ->expects($this->never())
            ->method('wasPublicRepoFileModifiedSince');

        $this->repository
            ->expects($this->once())
            ->method('getAllSummaryEntries')
            ->willReturn([new FilterListSummaryEntry('acme/package', FilterLists::MALWARE, '1.0.0')]);

        $this->cdn
            ->expects($this->once())
            ->method('uploadMetadata')
            ->with(FilterListSummaryDumper::SUMMARY_PATH, '{"filter":{"malware":{"acme/package":"1.0.0"}}}')
            ->willReturn(0);

        $this->cdn
            ->expects($this->once())
            ->method('purgeSummaryUrl')
            ->willReturn(true);

        $this->dumper->dumpIfStale(forceDump: true);
    }

    public function testNonForceDumpsWhenPublicRepoFileWasNotYetModified(): void
    {
        $newestCreatedAt = new \DateTimeImmutable('@2000');

        $this->repository
            ->expects($this->once())
            ->method('getNewestEntryUpdatedAt')
            ->willReturn($newestCreatedAt);

        $this->cdn
            ->expects($this->once())
            ->method('wasPublicRepoFileModifiedSince')
            ->with(FilterListSummaryDumper::SUMMARY_PATH, $newestCreatedAt)
            ->willReturn(false);

        $this->repository
            ->expects($this->once())
            ->method('getAllSummaryEntries')
            ->willReturn([new FilterListSummaryEntry('acme/package', FilterLists::MALWARE, '1.0.0')]);

        $this->cdn
            ->expects($this->once())
            ->method('uploadMetadata')
            ->with(FilterListSummaryDumper::SUMMARY_PATH, '{"filter":{"malware":{"acme/package":"1.0.0"}}}')
            ->willReturn(0);

        $this->cdn
            ->expects($this->once())
            ->method('purgeSummaryUrl')
            ->willReturn(true);

        $this->dumper->dumpIfStale(forceDump: false);
    }

    public function testNonForceSkipsWhenPublicRepoFileHasBeenModifiedSince(): void
    {
        $newestCreatedAt = new \DateTimeImmutable('@1000');

        $this->repository
            ->expects($this->once())
            ->method('getNewestEntryUpdatedAt')
            ->willReturn($newestCreatedAt);

        $this->cdn
            ->expects($this->once())
            ->method('wasPublicRepoFileModifiedSince')
            ->with(FilterListSummaryDumper::SUMMARY_PATH, $newestCreatedAt)
            ->willReturn(true);

        $this->repository
            ->expects($this->never())
            ->method('getAllSummaryEntries');

        $this->cdn
            ->expects($this->never())
            ->method('uploadMetadata');

        $this->cdn
            ->expects($this->never())
            ->method('purgeSummaryUrl');

        $this->dumper->dumpIfStale(forceDump: false);
    }

    public function testNonForceSkipsEntirelyWhenNoEntriesExist(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('getNewestEntryUpdatedAt')
            ->willReturn(null);

        $this->cdn
            ->expects($this->never())
            ->method('wasPublicRepoFileModifiedSince');

        $this->repository
            ->expects($this->never())
            ->method('getAllSummaryEntries');

        $this->cdn
            ->expects($this->never())
            ->method('uploadMetadata');

        $this->cdn
            ->expects($this->never())
            ->method('purgeSummaryUrl');

        $this->dumper->dumpIfStale(forceDump: false);
    }
}
