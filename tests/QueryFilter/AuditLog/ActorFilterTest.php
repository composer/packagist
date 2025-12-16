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
use App\QueryFilter\AuditLog\ActorFilter;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

class ActorFilterTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    public function testFromQueryWithEmptyInput(): void
    {
        $bag = new InputBag([]);
        $filter = ActorFilter::fromQuery($bag, 'actor', false);

        $this->assertSame('actor', $filter->getKey());
        $this->assertSame('', $filter->getSelectedValue());
    }

    public function testFromQueryWithValue(): void
    {
        $bag = new InputBag(['actor' => 'testuser']);
        $filter = ActorFilter::fromQuery($bag, 'actor', false);

        $this->assertSame('actor', $filter->getKey());
        $this->assertSame('testuser', $filter->getSelectedValue());
    }

    public function testFromQueryTrimsWhitespace(): void
    {
        $bag = new InputBag(['actor' => '  testuser  ']);
        $filter = ActorFilter::fromQuery($bag, 'actor', false);

        $this->assertSame('testuser', $filter->getSelectedValue());
    }

    public function testFilterWithEmptyValue(): void
    {
        $bag = new InputBag([]);
        $filter = ActorFilter::fromQuery($bag, 'actor', false);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $result = $filter->filter($qb);

        $this->assertSame($qb, $result);
        $this->assertNull($qb->getDQLPart('where'));
    }

    public function testFilterNonAdminExactMatch(): void
    {
        $bag = new InputBag(['actor' => 'testuser']);
        $filter = ActorFilter::fromQuery($bag, 'actor', false);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertNotNull($qb->getDQLPart('where'));
        $this->assertSame('testuser', $qb->getParameter('actor')->getValue());
        $this->assertStringContainsString('u.username = :actor', (string) $qb->getDQLPart('where'));
    }

    public function testFilterAdminWithWildcard(): void
    {
        $bag = new InputBag(['actor' => 'test*']);
        $filter = ActorFilter::fromQuery($bag, 'actor', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertSame('test%', $qb->getParameter('actor')->getValue());
        $this->assertStringContainsString('u.username LIKE :actor', (string) $qb->getDQLPart('where'));
    }

    public function testFilterAdminExactMatch(): void
    {
        $bag = new InputBag(['actor' => 'testuser']);
        $filter = ActorFilter::fromQuery($bag, 'actor', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertSame('testuser', $qb->getParameter('actor')->getValue());
        $this->assertStringContainsString('u.username = :actor', (string) $qb->getDQLPart('where'));
    }

    public function testFilterAdminEscapesSpecialCharacters(): void
    {
        $bag = new InputBag(['actor' => 'test%_user']);
        $filter = ActorFilter::fromQuery($bag, 'actor', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertSame('test\%\_user', $qb->getParameter('actor')->getValue());
    }

    public function testFilterAdminMultipleWildcards(): void
    {
        $bag = new InputBag(['actor' => 'test*user*']);
        $filter = ActorFilter::fromQuery($bag, 'actor', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertSame('test%user%', $qb->getParameter('actor')->getValue());
        $this->assertStringContainsString('u.username LIKE :actor', (string) $qb->getDQLPart('where'));
    }

    public function testFilterCreatesJoinWithUser(): void
    {
        $bag = new InputBag(['actor' => 'testuser']);
        $filter = ActorFilter::fromQuery($bag, 'actor', false);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $joins = $qb->getDQLPart('join');
        $this->assertNotEmpty($joins);
    }
}
