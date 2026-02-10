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
        $em = $this->createStub(EntityManager::class);

        $doctrine
            ->method('getManager')
            ->willReturn($em);

        $this->repo = $this->createMock(FilterListEntryRepository::class);
        $em
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
            ->method('getPackageVersionsFlaggedAsMalwareForPackageNames')
            ->with(['acme/package'])
            ->willReturn([
                'acme/package' => [
                    ['version' => '1.0.0', 'category' => 'malware', 'list' => 'test'],
                    ['version' => '2.0.0', 'category' => 'malware', 'list' => 'test'],
                ],
            ]);

        $this->assertEquals([
            'acme/package' => [
                'test' => [
                    new DumpableFilterList('1.0.0 || 2.0.0', '', 'malware', null),
                ],
            ]
        ], $this->filterListDumperProvider->getMalwareDataForDump(['acme/package']));
    }

    public function testNoEntries(): void
    {
        $this->repo
            ->expects($this->once())
            ->method('getPackageVersionsFlaggedAsMalwareForPackageNames')
            ->with(['acme/package'])
            ->willReturn([]);

        $this->assertSame([], $this->filterListDumperProvider->getMalwareDataForDump(['acme/package']));
    }
}
