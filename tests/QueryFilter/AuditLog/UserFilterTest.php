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
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

class UserFilterTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
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

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $result = $filter->filter($qb);

        $this->assertSame($qb, $result);
        $this->assertNull($qb->getDQLPart('where'));
    }

    public function testFilterNonAdminExactMatch(): void
    {
        $bag = new InputBag(['user' => 'testuser']);
        $filter = UserFilter::fromQuery($bag, 'user', false);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertNotNull($qb->getDQLPart('where'));
        $this->assertSame('testuser', $qb->getParameter('user')->getValue());
        $this->assertStringContainsString("JSON_EXTRACT(a.attributes, '$.user.username') = :user", (string) $qb->getDQLPart('where'));
    }

    public function testFilterAdminWithWildcard(): void
    {
        $bag = new InputBag(['user' => 'test*']);
        $filter = UserFilter::fromQuery($bag, 'user', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertSame('"test%"', $qb->getParameter('user')->getValue());
        $this->assertStringContainsString("JSON_EXTRACT(a.attributes, '$.user.username') LIKE :user", (string) $qb->getDQLPart('where'));
    }

    public function testFilterAdminExactMatch(): void
    {
        $bag = new InputBag(['user' => 'testuser']);
        $filter = UserFilter::fromQuery($bag, 'user', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertSame('testuser', $qb->getParameter('user')->getValue());
        $this->assertStringContainsString("JSON_EXTRACT(a.attributes, '$.user.username') = :user", (string) $qb->getDQLPart('where'));
    }

    public function testFilterAdminEscapesSpecialCharacters(): void
    {
        $bag = new InputBag(['user' => 'test%_user']);
        $filter = UserFilter::fromQuery($bag, 'user', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $result = $filter->filter($qb);

        $this->assertSame('"test\%\_user"', $qb->getParameter('user')->getValue());
    }

    public function testFilterAdminMultipleWildcards(): void
    {
        $bag = new InputBag(['user' => 'test*user*']);
        $filter = UserFilter::fromQuery($bag, 'user', true);

        $qb = new QueryBuilder($this->entityManager);
        $qb->from(AuditRecord::class, 'a');
        $filter->filter($qb);

        $this->assertSame('"test%user%"', $qb->getParameter('user')->getValue());
        $this->assertStringContainsString("JSON_EXTRACT(a.attributes, '$.user.username') LIKE :user", (string) $qb->getDQLPart('where'));
    }
}
