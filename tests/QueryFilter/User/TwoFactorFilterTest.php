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
use App\QueryFilter\User\TwoFactorFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class TwoFactorFilterTest extends TestCase
{
    private function queryBuilder(): QueryBuilder
    {
        $qb = new QueryBuilder($this->createStub(EntityManagerInterface::class));

        return $qb->from(User::class, 'u');
    }

    public function testEmptyValueDoesNotFilter(): void
    {
        $qb = $this->queryBuilder();
        $filter = TwoFactorFilter::fromQuery(new InputBag([]));
        $filter->filter($qb);

        self::assertSame('twofa', $filter->getKey());
        self::assertSame('', $filter->getSelectedValue());
        self::assertNull($qb->getDQLPart('where'));
    }

    public function testEnabledMatchesRowsWithASecret(): void
    {
        $qb = $this->queryBuilder();
        TwoFactorFilter::fromQuery(new InputBag(['twofa' => 'enabled']))->filter($qb);

        self::assertStringContainsString('u.totpSecret IS NOT NULL', (string) $qb->getDQLPart('where'));
    }

    public function testDisabledMatchesRowsWithoutASecret(): void
    {
        $qb = $this->queryBuilder();
        TwoFactorFilter::fromQuery(new InputBag(['twofa' => 'disabled']))->filter($qb);

        self::assertStringContainsString('u.totpSecret IS NULL', (string) $qb->getDQLPart('where'));
    }

    public function testUnknownValueIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        TwoFactorFilter::fromQuery(new InputBag(['twofa' => 'on']));
    }
}
