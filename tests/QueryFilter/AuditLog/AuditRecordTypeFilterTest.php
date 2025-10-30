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

use App\Audit\AuditRecordType;
use App\QueryFilter\AuditLog\AuditRecordTypeFilter;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

class AuditRecordTypeFilterTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    public function testFromQueryWithEmptyInput(): void
    {
        $bag = new InputBag([]);
        $filter = AuditRecordTypeFilter::fromQuery($bag);

        $this->assertSame('type', $filter->getKey());
        $this->assertSame([], $filter->getSelectedValue());
    }

    public function testFromQueryWithMultipleValidAndInvalidTypes(): void
    {
        $types = [
            AuditRecordType::PackageCreated->value,
            'invalid_type',
            AuditRecordType::VersionDeleted->value,
        ];

        $bag = new InputBag(['type' => $types]);
        $filter = AuditRecordTypeFilter::fromQuery($bag);

        $this->assertSame(
            [AuditRecordType::PackageCreated->value, AuditRecordType::VersionDeleted->value],
            $filter->getSelectedValue()
        );
    }

    public function testFilterWithEmptyTypes(): void
    {
        $bag = new InputBag([]);
        $filter = AuditRecordTypeFilter::fromQuery($bag);

        $qb = new QueryBuilder($this->entityManager);
        $result = $filter->filter($qb);

        $this->assertSame($qb, $result);
        $this->assertNull($qb->getDQLPart('where'));
    }

    public function testFilterWithTypes(): void
    {
        $types = [
            AuditRecordType::PackageCreated->value,
            AuditRecordType::VersionReferenceChanged->value,
        ];

        $bag = new InputBag(['type' => $types]);
        $filter = AuditRecordTypeFilter::fromQuery($bag);

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
