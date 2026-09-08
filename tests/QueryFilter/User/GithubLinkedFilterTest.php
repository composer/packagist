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
use App\QueryFilter\User\GithubLinkedFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class GithubLinkedFilterTest extends TestCase
{
    private function queryBuilder(): QueryBuilder
    {
        $qb = new QueryBuilder($this->createStub(EntityManagerInterface::class));

        return $qb->from(User::class, 'u');
    }

    public function testEmptyValueDoesNotFilter(): void
    {
        $qb = $this->queryBuilder();
        $filter = GithubLinkedFilter::fromQuery(new InputBag([]));
        $filter->filter($qb);

        self::assertSame('github_linked', $filter->getKey());
        self::assertNull($qb->getDQLPart('where'));
    }

    public function testYesMatchesLinkedAccounts(): void
    {
        $qb = $this->queryBuilder();
        GithubLinkedFilter::fromQuery(new InputBag(['github_linked' => 'yes']))->filter($qb);

        self::assertStringContainsString('u.githubId IS NOT NULL', (string) $qb->getDQLPart('where'));
    }

    public function testNoMatchesUnlinkedAccounts(): void
    {
        $qb = $this->queryBuilder();
        GithubLinkedFilter::fromQuery(new InputBag(['github_linked' => 'no']))->filter($qb);

        self::assertStringContainsString('u.githubId IS NULL', (string) $qb->getDQLPart('where'));
    }

    public function testUnknownValueIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        GithubLinkedFilter::fromQuery(new InputBag(['github_linked' => 'linked']));
    }
}
