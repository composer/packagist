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
use App\QueryFilter\User\UserSearchFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

class UserSearchFilterTest extends TestCase
{
    private function queryBuilder(): QueryBuilder
    {
        $qb = new QueryBuilder($this->createStub(EntityManagerInterface::class));

        return $qb->from(User::class, 'u');
    }

    public function testEmptyValueDoesNotFilter(): void
    {
        $qb = $this->queryBuilder();
        UserSearchFilter::fromQuery(new InputBag([]))->filter($qb);

        self::assertNull($qb->getDQLPart('where'));
    }

    public function testMatchesUsernameOrEmailWithEscapedWildcards(): void
    {
        $filter = UserSearchFilter::fromQuery(new InputBag(['search' => '  Al_ce%  ']));

        self::assertSame('Al_ce%', $filter->getSelectedValue());

        $qb = $this->queryBuilder();
        $filter->filter($qb);

        $where = (string) $qb->getDQLPart('where');
        self::assertStringContainsString('(u.usernameCanonical LIKE :search OR u.emailCanonical LIKE :search)', $where);
        self::assertSame('%al\_ce\%%', $qb->getParameter('search')->getValue());
    }
}
