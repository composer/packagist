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
use App\QueryFilter\AuditLog\UserFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

class UserFilterTest extends TestCase
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
        $filter = UserFilter::fromQuery($bag, 'user', false);

        $this->assertSame('user', $filter->getKey());
        $this->assertSame('', $filter->getSelectedValue());
    }

    public function testFromQueryWithValue(): void
    {
        $bag = new InputBag(['user' => 'testuser']);
        $filter = UserFilter::fromQuery($bag, 'user', false);

        $this->assertSame('user', $filter->getKey());
        $this->assertSame('testuser', $filter->getSelectedValue());
    }

    public function testFromQueryTrimsWhitespace(): void
    {
        $bag = new InputBag(['user' => '  testuser  ']);
        $filter = UserFilter::fromQuery($bag, 'user', false);

        $this->assertSame('testuser', $filter->getSelectedValue());
    }

    public function testFilterWithEmptyValue(): void
    {
        $bag = new InputBag([]);
        $filter = UserFilter::fromQuery($bag, 'user', false);

        $qb = $this->buildQueryBuilder();
        $result = $filter->filter($qb);

        $this->assertSame($qb, $result);
        $this->assertNull($qb->getDQLPart('where'));
    }

    public function testFilterNonAdminUsesSearchIndex(): void
    {
        $bag = new InputBag(['user' => 'testuser']);
        $filter = UserFilter::fromQuery($bag, 'user', false);

        $qb = $this->buildQueryBuilder();
        $filter->filter($qb);

        $where = (string) $qb->getDQLPart('where');
        $this->assertStringContainsString('a.id IN (SELECT user_search.auditLogId FROM', $where);
        $this->assertStringContainsString('AuditLogSearch user_search', $where);
        $this->assertStringContainsString('user_search.type = :userType', $where);
        $this->assertStringContainsString('user_search.name = :user', $where);
        $this->assertSame('user', $qb->getParameter('userType')->getValue());
        $this->assertSame('testuser', $qb->getParameter('user')->getValue());
    }

    public function testFilterLowercasesValueForCaseInsensitiveMatch(): void
    {
        $bag = new InputBag(['user' => 'TestUser']);
        $filter = UserFilter::fromQuery($bag, 'user', false);

        $qb = $this->buildQueryBuilder();
        $filter->filter($qb);

        $this->assertSame('testuser', $qb->getParameter('user')->getValue());
    }

    public function testFilterAdminWildcardUsesLike(): void
    {
        $bag = new InputBag(['user' => 'Test*']);
        $filter = UserFilter::fromQuery($bag, 'user', true);

        $qb = $this->buildQueryBuilder();
        $filter->filter($qb);

        $where = (string) $qb->getDQLPart('where');
        $this->assertStringContainsString('user_search.name LIKE :user', $where);
        $this->assertSame('test%', $qb->getParameter('user')->getValue());
    }

    public function testFilterAdminEscapesSpecialCharacters(): void
    {
        $bag = new InputBag(['user' => 'Test%_user*']);
        $filter = UserFilter::fromQuery($bag, 'user', true);

        $qb = $this->buildQueryBuilder();
        $filter->filter($qb);

        // "%" and "_" escaped (literal), "*" turned into the SQL wildcard "%", all lowercased
        $this->assertSame('test\%\_user%', $qb->getParameter('user')->getValue());
    }
}
