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

namespace App\Tests\QueryFilter\User;

use App\Entity\User;
use App\QueryFilter\User\FrozenStatusFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class FrozenStatusFilterTest extends TestCase
{
    private function queryBuilder(): QueryBuilder
    {
        $qb = new QueryBuilder($this->createStub(EntityManagerInterface::class));

        return $qb->from(User::class, 'u');
    }

    public function testEmptyValueDoesNotFilterOrNarrow(): void
    {
        $filter = FrozenStatusFilter::fromQuery(new InputBag([]));

        self::assertSame('frozen', $filter->getKey());
        self::assertSame('', $filter->getSelectedValue());
        self::assertFalse($filter->narrowsToFrozen());

        $qb = $this->queryBuilder();
        $filter->filter($qb);
        self::assertNull($qb->getDQLPart('where'));
    }

    public function testNoneMatchesUnfrozenAndDoesNotNarrow(): void
    {
        $filter = FrozenStatusFilter::fromQuery(new InputBag(['frozen' => 'none']));

        self::assertFalse($filter->narrowsToFrozen());

        $qb = $this->queryBuilder();
        $filter->filter($qb);
        self::assertStringContainsString('u.frozen IS NULL', (string) $qb->getDQLPart('where'));
    }

    public function testAnyMatchesFrozenAndNarrows(): void
    {
        $filter = FrozenStatusFilter::fromQuery(new InputBag(['frozen' => 'any']));

        self::assertTrue($filter->narrowsToFrozen());

        $qb = $this->queryBuilder();
        $filter->filter($qb);
        self::assertStringContainsString('u.frozen IS NOT NULL', (string) $qb->getDQLPart('where'));
    }

    public function testSpecificReasonBindsTheEnum(): void
    {
        $filter = FrozenStatusFilter::fromQuery(new InputBag(['frozen' => 'temporary']));

        self::assertTrue($filter->narrowsToFrozen());

        $qb = $this->queryBuilder();
        $filter->filter($qb);
        self::assertStringContainsString('u.frozen = :frozenReason', (string) $qb->getDQLPart('where'));
        self::assertSame('temporary', $qb->getParameter('frozenReason')->getValue()->value);
    }

    public function testUnknownValueIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        FrozenStatusFilter::fromQuery(new InputBag(['frozen' => 'frozen']));
    }
}
