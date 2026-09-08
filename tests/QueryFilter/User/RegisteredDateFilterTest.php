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
use App\QueryFilter\User\RegisteredAfterFilter;
use App\QueryFilter\User\RegisteredBeforeFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class RegisteredDateFilterTest extends TestCase
{
    private function queryBuilder(): QueryBuilder
    {
        $qb = new QueryBuilder($this->createStub(EntityManagerInterface::class));

        return $qb->from(User::class, 'u');
    }

    public function testAfterUsesStartOfDayAndGreaterEqual(): void
    {
        $filter = RegisteredAfterFilter::fromQuery(new InputBag(['registered_from' => '2025-01-15']));

        self::assertSame('registered_from', $filter->getKey());
        self::assertSame('2025-01-15', $filter->getSelectedValue());

        $qb = $this->queryBuilder();
        $filter->filter($qb);
        self::assertStringContainsString('u.createdAt >= :registered_from', (string) $qb->getDQLPart('where'));
        self::assertSame('2025-01-15 00:00:00', $qb->getParameter('registered_from')->getValue()->format('Y-m-d H:i:s'));
    }

    public function testBeforeUsesEndOfDayAndLessEqual(): void
    {
        $filter = RegisteredBeforeFilter::fromQuery(new InputBag(['registered_to' => '2025-01-15']));

        $qb = $this->queryBuilder();
        $filter->filter($qb);
        self::assertStringContainsString('u.createdAt <= :registered_to', (string) $qb->getDQLPart('where'));
        self::assertSame('2025-01-15 23:59:59', $qb->getParameter('registered_to')->getValue()->format('Y-m-d H:i:s'));
    }

    public function testEmptyValueDoesNotFilter(): void
    {
        $qb = $this->queryBuilder();
        RegisteredAfterFilter::fromQuery(new InputBag([]))->filter($qb);

        self::assertNull($qb->getDQLPart('where'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'rolled over day' => ['2026-02-31'];
        yield 'rolled over month' => ['2026-13-01'];
        yield 'wrong format' => ['01/2026'];
        yield 'not a date' => ['soon'];
    }

    #[DataProvider('invalidDates')]
    public function testInvalidDateIsRejected(string $value): void
    {
        $this->expectException(BadRequestHttpException::class);
        RegisteredAfterFilter::fromQuery(new InputBag(['registered_from' => $value]));
    }
}
