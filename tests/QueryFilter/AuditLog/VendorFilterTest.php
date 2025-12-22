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
use App\QueryFilter\AuditLog\VendorFilter;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

class VendorFilterTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
    }

    public function testFromQueryWithEmptyInput(): void
    {
        $bag = new InputBag([]);
        $filter = VendorFilter::fromQuery($bag, 'vendor', false);

        $this->assertSame('vendor', $filter->getKey());
        $this->assertSame('', $filter->getSelectedValue());
    }

    public function testFromQueryWithValue(): void
    {
        $bag = new InputBag(['vendor' => 'testvendor']);
        $filter = VendorFilter::fromQuery($bag, 'vendor', false);

        $this->assertSame('vendor', $filter->getKey());
        $this->assertSame('testvendor', $filter->getSelectedValue());
    }

    public function testFromQueryTrimsWhitespace(): void
    {
        $bag = new InputBag(['vendor' => '  testvendor  ']);
        $filter = VendorFilter::fromQuery($bag, 'vendor', false);

        $this->assertSame('testvendor', $filter->getSelectedValue());
    }

    public function testFilterWithEmptyValue(): void
    {
        $bag = new InputBag([]);
        $filter = VendorFilter::fromQuery($bag, 'vendor', false);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $result = $filter->filter($qb);

        $this->assertSame($qb, $result);
        $this->assertNull($qb->getDQLPart('where'));
    }

    public function testFilterNonAdminExactMatch(): void
    {
        $bag = new InputBag(['vendor' => 'testvendor']);
        $filter = VendorFilter::fromQuery($bag, 'vendor', false);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertNotNull($qb->getDQLPart('where'));
        $this->assertSame('testvendor', $qb->getParameter('vendor')->getValue());
        $this->assertStringContainsString('a.vendor = :vendor', (string) $qb->getDQLPart('where'));
    }

    public function testFilterAdminWithWildcard(): void
    {
        $bag = new InputBag(['vendor' => 'test*']);
        $filter = VendorFilter::fromQuery($bag, 'vendor', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertSame('test%', $qb->getParameter('vendor')->getValue());
        $this->assertStringContainsString('a.vendor LIKE :vendor', (string) $qb->getDQLPart('where'));
    }

    public function testFilterAdminExactMatch(): void
    {
        $bag = new InputBag(['vendor' => 'testvendor']);
        $filter = VendorFilter::fromQuery($bag, 'vendor', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertSame('testvendor', $qb->getParameter('vendor')->getValue());
        $this->assertStringContainsString('a.vendor = :vendor', (string) $qb->getDQLPart('where'));
    }

    public function testFilterAdminEscapesSpecialCharacters(): void
    {
        $bag = new InputBag(['vendor' => 'test%_vendor*']);
        $filter = VendorFilter::fromQuery($bag, 'vendor', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertSame('test\%\_vendor%', $qb->getParameter('vendor')->getValue());
    }

    public function testFilterAdminMultipleWildcards(): void
    {
        $bag = new InputBag(['vendor' => 'test*vendor*']);
        $filter = VendorFilter::fromQuery($bag, 'vendor', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertSame('test%vendor%', $qb->getParameter('vendor')->getValue());
        $this->assertStringContainsString('a.vendor LIKE :vendor', (string) $qb->getDQLPart('where'));
    }

    public function testFilterDoesNotCreateJoins(): void
    {
        $bag = new InputBag(['vendor' => 'testvendor']);
        $filter = VendorFilter::fromQuery($bag, 'vendor', false);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $joins = $qb->getDQLPart('join');
        $this->assertEmpty($joins);
    }
}
