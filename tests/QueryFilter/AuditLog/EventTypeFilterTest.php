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

use App\Log\AuditLogEventType;
use App\QueryFilter\AuditLog\EventTypeFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

class EventTypeFilterTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
    }

    public function testFromQueryWithEmptyInput(): void
    {
        $bag = new InputBag([]);
        $filter = EventTypeFilter::fromQuery($bag);

        $this->assertSame('type', $filter->getKey());
        $this->assertSame([], $filter->getSelectedValue());
    }

    public function testFromQueryWithMultipleValidAndInvalidTypes(): void
    {
        $types = [
            AuditLogEventType::PackageCreated->value,
            'invalid_type',
            AuditLogEventType::VersionDeleted->value,
        ];

        $bag = new InputBag(['type' => $types]);
        $filter = EventTypeFilter::fromQuery($bag);

        $this->assertSame(
            [AuditLogEventType::PackageCreated->value, AuditLogEventType::VersionDeleted->value],
            $filter->getSelectedValue()
        );
    }

    public function testFilterWithEmptyTypes(): void
    {
        $bag = new InputBag([]);
        $filter = EventTypeFilter::fromQuery($bag);

        $qb = new QueryBuilder($this->entityManager);
        $result = $filter->filter($qb);

        $this->assertSame($qb, $result);
        $this->assertNull($qb->getDQLPart('where'));
    }

    public function testFilterWithTypes(): void
    {
        $types = [
            AuditLogEventType::PackageCreated->value,
            AuditLogEventType::VersionCreated->value,
        ];

        $bag = new InputBag(['type' => $types]);
        $filter = EventTypeFilter::fromQuery($bag);

        $qb = new QueryBuilder($this->entityManager);
        $result = $filter->filter($qb);

        $this->assertSame($qb, $result);
        $this->assertNotNull($qb->getDQLPart('where'));
        $this->assertEqualsCanonicalizing(
            $types,
            $qb->getParameter('types')->getValue()
        );
    }
}
