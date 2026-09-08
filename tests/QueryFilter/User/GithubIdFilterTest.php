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
use App\QueryFilter\User\GithubIdFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

class GithubIdFilterTest extends TestCase
{
    private function queryBuilder(): QueryBuilder
    {
        $qb = new QueryBuilder($this->createStub(EntityManagerInterface::class));

        return $qb->from(User::class, 'u');
    }

    public function testEmptyValueDoesNotFilter(): void
    {
        $qb = $this->queryBuilder();
        GithubIdFilter::fromQuery(new InputBag([]))->filter($qb);

        self::assertNull($qb->getDQLPart('where'));
    }

    public function testExactMatchOnTrimmedValue(): void
    {
        $filter = GithubIdFilter::fromQuery(new InputBag(['github_id' => '  424242 ']));

        self::assertSame('424242', $filter->getSelectedValue());

        $qb = $this->queryBuilder();
        $filter->filter($qb);
        self::assertStringContainsString('u.githubId = :githubId', (string) $qb->getDQLPart('where'));
        self::assertSame('424242', $qb->getParameter('githubId')->getValue());
    }
}
