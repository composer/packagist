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

namespace App\Tests\QueryFilter\AuditLog;

use App\Entity\AuditRecord;
use App\QueryFilter\AuditLog\PackageNameFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

class PackageNameFilterTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
    }

    private function buildQueryBuilder(): QueryBuilder
    {
        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');

        return $qb;
    }

    public function testFromQueryWithEmptyInput(): void
    {
        $bag = new InputBag([]);
        $filter = PackageNameFilter::fromQuery($bag, 'package', false);

        $this->assertSame('package', $filter->getKey());
        $this->assertSame('', $filter->getSelectedValue());
    }

    public function testFromQueryWithValue(): void
    {
        $bag = new InputBag(['package' => 'vendor/package']);
        $filter = PackageNameFilter::fromQuery($bag, 'package', false);

        $this->assertSame('package', $filter->getKey());
        $this->assertSame('vendor/package', $filter->getSelectedValue());
    }

    public function testFromQueryTrimsWhitespace(): void
    {
        $bag = new InputBag(['package' => '  vendor/package  ']);
        $filter = PackageNameFilter::fromQuery($bag, 'package', false);

        $this->assertSame('vendor/package', $filter->getSelectedValue());
    }

    public function testFilterWithEmptyValue(): void
    {
        $bag = new InputBag([]);
        $filter = PackageNameFilter::fromQuery($bag, 'package', false);

        $qb = $this->buildQueryBuilder();
        $result = $filter->filter($qb);

        $this->assertSame($qb, $result);
        $this->assertNull($qb->getDQLPart('where'));
    }

    public function testFilterNonAdminUsesSearchIndex(): void
    {
        $bag = new InputBag(['package' => 'vendor/package']);
        $filter = PackageNameFilter::fromQuery($bag, 'package', false);

        $qb = $this->buildQueryBuilder();
        $filter->filter($qb);

        $where = (string) $qb->getDQLPart('where');
        $this->assertStringContainsString('a.id IN (SELECT package_search.auditLogId FROM', $where);
        $this->assertStringContainsString('AuditLogSearch package_search', $where);
        $this->assertStringContainsString('package_search.type = :packageType', $where);
        $this->assertStringContainsString('package_search.name = :package', $where);
        $this->assertSame('package', $qb->getParameter('packageType')->getValue());
        $this->assertSame('vendor/package', $qb->getParameter('package')->getValue());
    }

    public function testFilterLowercasesValueForCaseInsensitiveMatch(): void
    {
        $bag = new InputBag(['package' => 'Vendor/Package']);
        $filter = PackageNameFilter::fromQuery($bag, 'package', false);

        $qb = $this->buildQueryBuilder();
        $filter->filter($qb);

        $this->assertSame('vendor/package', $qb->getParameter('package')->getValue());
    }

    public function testFilterAdminWildcardUsesLike(): void
    {
        $bag = new InputBag(['package' => 'Vendor/*']);
        $filter = PackageNameFilter::fromQuery($bag, 'package', true);

        $qb = $this->buildQueryBuilder();
        $filter->filter($qb);

        $where = (string) $qb->getDQLPart('where');
        $this->assertStringContainsString('package_search.name LIKE :package', $where);
        $this->assertSame('vendor/%', $qb->getParameter('package')->getValue());
    }

    public function testFilterAdminEscapesSpecialCharacters(): void
    {
        $bag = new InputBag(['package' => 'vendor%/package_*']);
        $filter = PackageNameFilter::fromQuery($bag, 'package', true);

        $qb = $this->buildQueryBuilder();
        $filter->filter($qb);

        $this->assertSame('vendor\%/package\_%', $qb->getParameter('package')->getValue());
    }

    public function testFilterAppliesVendorPreFilterForExactPackageName(): void
    {
        $bag = new InputBag(['package' => 'Vendor/Package']);
        $filter = PackageNameFilter::fromQuery($bag, 'package', false);

        $qb = $this->buildQueryBuilder();
        $filter->filter($qb);

        // vendor is derived from the lowercased pattern so it matches the (lowercase) vendor column
        $this->assertSame('vendor', $qb->getParameter('pkgVendor')->getValue());
        $this->assertStringContainsString('a.vendor = :pkgVendor', (string) $qb->getDQLPart('where'));
    }

    public function testFilterAppliesVendorPreFilterWhenWildcardOnlyInPackageSegment(): void
    {
        $bag = new InputBag(['package' => 'vendor/pack*']);
        $filter = PackageNameFilter::fromQuery($bag, 'package', true);

        $qb = $this->buildQueryBuilder();
        $filter->filter($qb);

        $this->assertSame('vendor', $qb->getParameter('pkgVendor')->getValue());
        $this->assertStringContainsString('a.vendor = :pkgVendor', (string) $qb->getDQLPart('where'));
    }

    public function testFilterSkipsVendorPreFilterWhenWildcardInVendorSegment(): void
    {
        $bag = new InputBag(['package' => 'ven*/package']);
        $filter = PackageNameFilter::fromQuery($bag, 'package', true);

        $qb = $this->buildQueryBuilder();
        $filter->filter($qb);

        // The vendor segment "ven%" cannot match via an exact-match pre-filter, so it must be skipped
        $this->assertNull($qb->getParameter('pkgVendor'));
        $this->assertStringNotContainsString('a.vendor', (string) $qb->getDQLPart('where'));
        $this->assertStringContainsString('package_search.name LIKE :package', (string) $qb->getDQLPart('where'));
    }
}
