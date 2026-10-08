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

namespace App\Tests\Model;

use App\Entity\PackageTransparencyLog;
use App\Entity\PackageTransparencyLogRepository;
use App\Model\CappedCountQueryAdapter;
use App\Service\TransparencyLogProjector;
use App\Tests\IntegrationTestCase;
use Pagerfanta\Pagerfanta;

class CappedCountQueryAdapterTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        for ($i = 1; $i <= 5; $i++) {
            $this->store(self::createPackage('capped/package'.$i, 'https://github.com/capped/package'.$i));
        }
        self::getService(TransparencyLogProjector::class)->project(0);
    }

    public function testCountsExactlyBelowTheCap(): void
    {
        self::assertSame(5, $this->adapter(10)->getNbResults());
    }

    public function testCountStopsAtTheCap(): void
    {
        self::assertSame(3, $this->adapter(3)->getNbResults());
    }

    public function testPagesPastTheCapAreNotReachable(): void
    {
        $paginator = new Pagerfanta($this->adapter(4));
        $paginator->setMaxPerPage(2);
        $paginator->setMaxNbPages(2);
        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setCurrentPage(99);

        self::assertSame(2, $paginator->getCurrentPage());
        self::assertSame(
            // newest first, so package1 is past the cap
            ['capped/package3', 'capped/package2'],
            array_map(static fn (PackageTransparencyLog $entry): string => $entry->packageName, iterator_to_array($paginator->getCurrentPageResults(), false)),
        );
    }

    /**
     * @param positive-int $maxResults
     *
     * @return CappedCountQueryAdapter<PackageTransparencyLog>
     */
    private function adapter(int $maxResults): CappedCountQueryAdapter
    {
        $qb = self::getService(PackageTransparencyLogRepository::class)->getQueryBuilderForPublicView()
            ->andWhere('t.vendor = :vendor')
            ->setParameter('vendor', 'capped');

        return new CappedCountQueryAdapter($qb, $maxResults);
    }
}
