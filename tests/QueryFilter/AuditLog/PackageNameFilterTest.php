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
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

class PackageNameFilterTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
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

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $result = $filter->filter($qb);

        $this->assertSame($qb, $result);
        $this->assertNull($qb->getDQLPart('where'));
    }

    public function testFilterNonAdminExactMatch(): void
    {
        $bag = new InputBag(['package' => 'vendor/package']);
        $filter = PackageNameFilter::fromQuery($bag, 'package', false);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertNotNull($qb->getDQLPart('where'));
        $this->assertSame('vendor/package', $qb->getParameter('package')->getValue());
        $this->assertStringContainsString("JSON_EXTRACT(a.attributes, '$.name') = :package", (string) $qb->getDQLPart('where'));
    }

    public function testFilterAdminWithWildcard(): void
    {
        $bag = new InputBag(['package' => 'vendor/*']);
        $filter = PackageNameFilter::fromQuery($bag, 'package', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertSame('"vendor/%"', $qb->getParameter('package')->getValue());
        $this->assertStringContainsString("JSON_EXTRACT(a.attributes, '$.name') LIKE :package", (string) $qb->getDQLPart('where'));
    }

    public function testFilterAdminExactMatch(): void
    {
        $bag = new InputBag(['package' => 'vendor/package']);
        $filter = PackageNameFilter::fromQuery($bag, 'package', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertSame('vendor/package', $qb->getParameter('package')->getValue());
        $this->assertStringContainsString("JSON_EXTRACT(a.attributes, '$.name') = :package", (string) $qb->getDQLPart('where'));
    }

    public function testFilterAdminEscapesSpecialCharacters(): void
    {
        $bag = new InputBag(['package' => 'vendor%/package_']);
        $filter = PackageNameFilter::fromQuery($bag, 'package', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertSame('"vendor\%/package\_"', $qb->getParameter('package')->getValue());
    }

    public function testFilterAdminMultipleWildcards(): void
    {
        $bag = new InputBag(['package' => 'vendor/pack*age*']);
        $filter = PackageNameFilter::fromQuery($bag, 'package', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertSame('"vendor/pack%age%"', $qb->getParameter('package')->getValue());
        $this->assertStringContainsString("JSON_EXTRACT(a.attributes, '$.name') LIKE :package", (string) $qb->getDQLPart('where'));
    }

    public function testFilterAdminWildcardWrapsInQuotes(): void
    {
        $bag = new InputBag(['package' => 'test*']);
        $filter = PackageNameFilter::fromQuery($bag, 'package', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $pattern = $qb->getParameter('package')->getValue();
        $this->assertStringStartsWith('"', $pattern);
        $this->assertStringEndsWith('"', $pattern);
    }
}
