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

namespace App\Tests\Entity;

use App\Entity\FilterListEntry;
use App\Entity\FilterListEntryRepository;
use App\FilterList\Dump\FilterListSummaryEntry;
use App\FilterList\FilterLists;
use App\FilterList\FilterSources;
use App\FilterList\RemoteFilterListEntry;
use App\Tests\IntegrationTestCase;

class FilterListEntryRepositoryTest extends IntegrationTestCase
{
    private FilterListEntryRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getEM()->getRepository(FilterListEntry::class);
    }

    public function testGetAllSummaryEntries(): void
    {
        $entry1 = new FilterListEntry($this->createRemote('vendor/malware-a', '1.0.0'));
        $entry2 = new FilterListEntry($this->createRemote('vendor/malware-b', '2.3.4'));
        $this->store($entry1, $entry2);

        $summaries = $this->repository->getAllSummaryEntries();

        $this->assertCount(2, $summaries);
        $this->assertContainsOnlyInstancesOf(FilterListSummaryEntry::class, $summaries);

        $byPackage = [];
        foreach ($summaries as $summary) {
            $byPackage[$summary->packageName] = $summary;
        }

        $this->assertArrayHasKey('vendor/malware-a', $byPackage);
        $this->assertSame('1.0.0', $byPackage['vendor/malware-a']->version);
        $this->assertSame(FilterLists::MALWARE, $byPackage['vendor/malware-a']->list);

        $this->assertArrayHasKey('vendor/malware-b', $byPackage);
        $this->assertSame('2.3.4', $byPackage['vendor/malware-b']->version);
        $this->assertSame(FilterLists::MALWARE, $byPackage['vendor/malware-b']->list);
    }

    public function testGetAllSummaryEntriesReturnsEmptyArrayWhenNoEntriesExist(): void
    {
        $this->assertSame([], $this->repository->getAllSummaryEntries());
    }

    public function testGetNewestEntryCreatedAtReturnsLatestCreatedAt(): void
    {
        $older = new FilterListEntry($this->createRemote('vendor/older', '1.0.0'));
        $newer = new FilterListEntry($this->createRemote('vendor/newer', '1.0.0'));

        $olderDate = new \DateTimeImmutable('2024-01-01 10:00:00');
        $newerDate = new \DateTimeImmutable('2025-06-15 18:30:00');
        $this->setCreatedAt($older, $olderDate);
        $this->setCreatedAt($newer, $newerDate);

        $this->store($older, $newer);

        $newest = $this->repository->getNewestEntryCreatedAt();

        $this->assertNotNull($newest);
        $this->assertSame($newerDate->format('Y-m-d H:i:s'), $newest->format('Y-m-d H:i:s'));
    }

    public function testGetNewestEntryCreatedAtReturnsNullWhenNoEntriesExist(): void
    {
        $this->assertNull($this->repository->getNewestEntryCreatedAt());
    }

    private function createRemote(string $packageName, string $version): RemoteFilterListEntry
    {
        return new RemoteFilterListEntry(
            $packageName,
            $version,
            FilterLists::MALWARE,
            'https://example.com/'.$packageName,
            'malware',
            FilterSources::AIKIDO,
        );
    }

    private function setCreatedAt(FilterListEntry $entry, \DateTimeImmutable $createdAt): void
    {
        new \ReflectionProperty($entry, 'createdAt')->setValue($entry, $createdAt);
    }
}
