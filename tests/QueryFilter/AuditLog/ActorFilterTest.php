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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

class ActorFilterTest extends TestCase
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

        $qb = $this->buildQueryBuilder();
        $result = $filter->filter($qb);

        $this->assertSame($qb, $result);
        $this->assertNull($qb->getDQLPart('where'));
    }

    public function testFilterNonAdminUsesSearchIndex(): void
    {
        $bag = new InputBag(['actor' => 'testuser']);
        $filter = ActorFilter::fromQuery($bag, 'actor', false);

        $qb = $this->buildQueryBuilder();
        $filter->filter($qb);

        $where = (string) $qb->getDQLPart('where');
        $this->assertStringContainsString('a.id IN (SELECT actor_search.auditLogId FROM', $where);
        $this->assertStringContainsString('AuditLogSearch actor_search', $where);
        $this->assertStringContainsString('actor_search.type = :actorType', $where);
        $this->assertStringContainsString('actor_search.name = :actor', $where);
        $this->assertSame('actor', $qb->getParameter('actorType')->getValue());
        $this->assertSame('testuser', $qb->getParameter('actor')->getValue());
    }

    public function testFilterLowercasesValueForCaseInsensitiveMatch(): void
    {
        $bag = new InputBag(['actor' => 'TestUser']);
        $filter = ActorFilter::fromQuery($bag, 'actor', false);

        $qb = $this->buildQueryBuilder();
        $filter->filter($qb);

        $this->assertSame('testuser', $qb->getParameter('actor')->getValue());
    }

    public function testFilterAdminWildcardUsesLike(): void
    {
        $bag = new InputBag(['actor' => 'Test*']);
        $filter = ActorFilter::fromQuery($bag, 'actor', true);

        $qb = $this->buildQueryBuilder();
        $filter->filter($qb);

        $where = (string) $qb->getDQLPart('where');
        $this->assertStringContainsString('actor_search.name LIKE :actor', $where);
        $this->assertSame('test%', $qb->getParameter('actor')->getValue());
    }

    public function testFilterAdminEscapesSpecialCharacters(): void
    {
        $bag = new InputBag(['actor' => 'Test%_user*']);
        $filter = ActorFilter::fromQuery($bag, 'actor', true);

        $qb = $this->buildQueryBuilder();
        $filter->filter($qb);

        $this->assertSame('test\%\_user%', $qb->getParameter('actor')->getValue());
    }
}
