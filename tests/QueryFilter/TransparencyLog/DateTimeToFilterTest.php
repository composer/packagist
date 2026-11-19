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

namespace App\Tests\QueryFilter\TransparencyLog;

use App\Entity\PackageTransparencyLog;
use App\QueryFilter\TransparencyLog\DateTimeToFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

class DateTimeToFilterTest extends TestCase
{
    #[TestWith(['2025-06-30', '2025-06-30 23:59:59'])]
    #[TestWith(['2025-06-30T10:15:30', '2025-06-30 10:15:30'])]
    #[TestWith(['2025-06-30T00:00:00', '2025-06-30 00:00:00'])]
    #[TestWith(['2025-06-30T10:15', '2025-06-30 10:15:00'])]
    public function testDateWithoutATimeIncludesTheWholeDay(string $value, string $expected): void
    {
        $qb = $this->filter($value);

        $parameter = $qb->getParameter('datetime_to');
        self::assertNotNull($parameter);
        self::assertInstanceOf(\DateTimeImmutable::class, $parameter->getValue());
        self::assertSame($expected, $parameter->getValue()->format('Y-m-d H:i:s'));
        self::assertSame($expected, DateTimeToFilter::fromQuery(new InputBag(['datetime_to' => $value]))->getDateTime()?->format('Y-m-d H:i:s'));
    }

    #[TestWith([''])]
    #[TestWith(['not-a-date'])]
    public function testInvalidOrEmptyValueDoesNotFilter(string $value): void
    {
        $qb = $this->filter($value);

        self::assertNull($qb->getParameter('datetime_to'));
        self::assertNull($qb->getDQLPart('where'));
    }

    private function filter(string $value): QueryBuilder
    {
        $qb = new QueryBuilder($this->createStub(EntityManagerInterface::class));
        $qb->select('t')->from(PackageTransparencyLog::class, 't');

        return DateTimeToFilter::fromQuery(new InputBag(['datetime_to' => $value]))->filter($qb);
    }
}
