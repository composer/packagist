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

use App\Entity\FilterListEntry;
use App\Entity\FilterListEntryRepository;
use App\FilterList\Dump\DumpableFilterList;
use App\FilterList\Dump\FilterListDumperProvider;
use App\FilterList\FilterSources;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class FilterListDumperProviderTest extends TestCase
{
    private FilterListDumperProvider $filterListDumperProvider;
    private FilterListEntryRepository&MockObject $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $doctrine = $this->createStub(ManagerRegistry::class);
        $em = $this->createMock(EntityManager::class);

        $doctrine
            ->method('getManager')
            ->willReturn($em);

        $this->repo = $this->createMock(FilterListEntryRepository::class);
        $em
            ->expects(self::once())
            ->method('getRepository')
            ->with(FilterListEntry::class)
            ->willReturn($this->repo);

        $this->filterListDumperProvider = new FilterListDumperProvider(
            $doctrine,
            $this->createStub(UrlGeneratorInterface::class),
        );
    }

    public function testGetMalwareDataForDump(): void
    {
        $this->repo
            ->expects($this->once())
            ->method('getAllPackageEntriesMap')
            ->with(['acme/package'])
            ->willReturn([
                'acme/package' => [
                    ['version' => '1.0.0', 'reason' => 'malware', 'list' => 'test', 'publicId' => 'PKFE-test1', 'source' => 'aikido'],
                    ['version' => '2.0.0', 'reason' => 'malware', 'list' => 'test', 'publicId' => 'PKFE-test2', 'source' => 'aikido'],
                ],
            ]);

        $this->assertEquals([
            'acme/package' => [
                'test' => [
                    new DumpableFilterList('1.0.0', '', 'malware', 'PKFE-test1', 'aikido'),
                    new DumpableFilterList('2.0.0', '', 'malware', 'PKFE-test2', 'aikido'),
                ],
            ],
        ], $this->filterListDumperProvider->getEntriesForDump(['acme/package']));
    }

    public function testNoEntries(): void
    {
        $this->repo
            ->expects($this->once())
            ->method('getAllPackageEntriesMap')
            ->with(['acme/package'])
            ->willReturn([]);

        $this->assertSame([], $this->filterListDumperProvider->getEntriesForDump(['acme/package']));
    }
}
