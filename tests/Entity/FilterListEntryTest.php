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
use App\FilterList\FilterLists;
use App\FilterList\FilterSources;
use App\FilterList\RemoteFilterListEntry;
use App\Form\Model\FilterListEntryRequest;
use PHPUnit\Framework\TestCase;

class FilterListEntryTest extends TestCase
{
    public function testFromRemoteKeepsTheReportTime(): void
    {
        $reportedAt = new \DateTimeImmutable('2026-01-01 00:00:00');

        $entry = FilterListEntry::fromRemote(new RemoteFilterListEntry('vendor/pkg', '1.0.0', FilterLists::MALWARE, null, 'malware', FilterSources::SOCKET, $reportedAt));

        $this->assertSame($reportedAt, $entry->getReportedAt());
    }

    public function testFromRemoteWithoutReportTime(): void
    {
        $entry = FilterListEntry::fromRemote(new RemoteFilterListEntry('vendor/pkg', '1.0.0', FilterLists::MALWARE, null, 'malware', FilterSources::AIKIDO));

        $this->assertNull($entry->getReportedAt());
    }

    public function testManualEntriesAreReportedWhenCreated(): void
    {
        $request = new FilterListEntryRequest();
        $request->list = FilterLists::MALWARE;
        $request->packageName = 'vendor/pkg';
        $request->version = '1.0.0';

        $entry = FilterListEntry::createManual($request);

        $this->assertSame($entry->getCreatedAt(), $entry->getReportedAt());
    }
}
