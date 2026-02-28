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

namespace App\Tests\FilterList;

use App\Entity\FilterListEntry;
use App\FilterList\FilterListCategories;
use App\FilterList\FilterListResolver;
use App\FilterList\FilterLists;
use App\FilterList\RemoteFilterListEntry;
use PHPUnit\Framework\TestCase;

class FilterListResolverTest extends TestCase
{
    private FilterListResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FilterListResolver();
    }

    public function testResolveAddNewEntry(): void
    {
        $remote = $this->createRemoteFilterListEntry('vendor/package', '1.0.0');
        $result = $this->resolver->resolve([], [$remote]);

        $this->assertEntry($remote, $result[0]);
        $this->assertSame([], $result[1]);
    }

    public function testResolveRemoveOldEntry(): void
    {
        $existing = new FilterListEntry($this->createRemoteFilterListEntry('vendor/package', '1.0.0'));
        $result = $this->resolver->resolve([$existing], []);

        $this->assertSame([], $result[0]);
        $this->assertSame([$existing], $result[1]);
    }

    public function testResolveExistingMatchesRemote(): void
    {
        $remote = $this->createRemoteFilterListEntry('vendor/package', '1.0.0');
        $existing = new FilterListEntry($remote);
        $result = $this->resolver->resolve([$existing], [$remote]);

        $this->assertSame([], $result[0]);
        $this->assertSame([], $result[1]);
    }

    public function testResolveMixed(): void
    {
        $existingKeep = new FilterListEntry($this->createRemoteFilterListEntry('vendor/keep', '1.0.0'));
        $existingRemove = new FilterListEntry($this->createRemoteFilterListEntry('vendor/remove', '2.0.0'));

        $remoteKeep = $this->createRemoteFilterListEntry('vendor/keep', '1.0.0');
        $remoteNew = $this->createRemoteFilterListEntry('vendor/new-pkg', '3.0.0');

        $result = $this->resolver->resolve(
            [$existingKeep, $existingRemove],
            [$remoteKeep, $remoteNew],
        );

        $this->assertEntry($remoteNew, $result[0]);
        $this->assertSame([$existingRemove], $result[1]);
    }

    public function testResolveEmpty(): void
    {
        $result = $this->resolver->resolve([], []);

        $this->assertSame([[], []], $result);
    }

    public function testResolveMultipleVersionsSamePackage(): void
    {
        $remote1 = $this->createRemoteFilterListEntry('vendor/package', '1.0.0');
        $remote2 = $this->createRemoteFilterListEntry('vendor/package', '2.0.0');
        $existing = new FilterListEntry($this->createRemoteFilterListEntry('vendor/package', '1.0.0'));

        $result = $this->resolver->resolve([$existing], [$remote1, $remote2]);

        $this->assertEntry($remote2, $result[0]);
        $this->assertSame([], $result[1]);
    }

    public function testResolveRemovesOnlyUnmatchedVersions(): void
    {
        $existing1 = new FilterListEntry($this->createRemoteFilterListEntry('vendor/package', '1.0.0'));
        $existing2 = new FilterListEntry($this->createRemoteFilterListEntry('vendor/package', '2.0.0'));

        $remote = $this->createRemoteFilterListEntry('vendor/package', '1.0.0');

        $result = $this->resolver->resolve([$existing1, $existing2], [$remote]);

        $this->assertSame([], $result[0]);
        $this->assertSame([$existing2], $result[1]);
    }

    private function createRemoteFilterListEntry(string $packageName, string $version): RemoteFilterListEntry
    {
        return new RemoteFilterListEntry(
            $packageName,
            $version,
            FilterLists::AIKIDO_MALWARE,
            FilterListCategories::MALWARE,
            'https://example.com/' . $packageName,
        );
    }

    /**
     * @param list<FilterListEntry> $new
     */
    private function assertEntry(RemoteFilterListEntry $remote, array $new): void
    {
        $this->assertCount(1, $new);
        $this->assertSame($remote->packageName, $new[0]->getPackageName());
        $this->assertSame($remote->version, $new[0]->getVersion());
        $this->assertSame($remote->list, $new[0]->getList());
        $this->assertSame($remote->category, $new[0]->getCategory());
    }
}
