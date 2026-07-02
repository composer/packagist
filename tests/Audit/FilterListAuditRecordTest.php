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

namespace App\Tests\Audit;

use App\Entity\AuditRecord;
use App\Entity\FilterListEntry;
use App\FilterList\FilterLists;
use App\FilterList\FilterSources;
use App\FilterList\RemoteFilterListEntry;
use PHPUnit\Framework\TestCase;

class FilterListAuditRecordTest extends TestCase
{
    public function testFilterListEntryAddedStoresTopLevelNameAndVendor(): void
    {
        $record = AuditRecord::filterListEntryAdded($this->createEntry('acme/package'), null);

        // top-level name lets PackageNameFilter ($.name) match these records
        self::assertSame('acme/package', $record->attributes['name']);
        // the entry snapshot is left intact
        self::assertSame('acme/package', $record->attributes['entry']['package_name']);
        self::assertSame('acme', $record->vendor);
    }

    public function testFilterListEntryDeletedStoresTopLevelNameAndVendor(): void
    {
        $record = AuditRecord::filterListEntryDeleted($this->createEntry('acme/package'), null);

        self::assertSame('acme/package', $record->attributes['name']);
        self::assertSame('acme/package', $record->attributes['entry']['package_name']);
        self::assertSame('acme', $record->vendor);
    }

    private function createEntry(string $packageName): FilterListEntry
    {
        return FilterListEntry::fromRemote(new RemoteFilterListEntry(
            $packageName,
            '<1.0',
            FilterLists::MALWARE,
            null,
            'malware',
            FilterSources::AIKIDO,
        ));
    }
}
