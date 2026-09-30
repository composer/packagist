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

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\UserFreezeReason;
use App\Entity\UserRepository;
use App\Tests\IntegrationTestCase;

class UserRepositoryTest extends IntegrationTestCase
{
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = self::getEM()->getRepository(User::class);
    }

    public function testGitHubIdIsIndexed(): void
    {
        // findOneBy(['githubId' => ...]) runs on every GitHub login and had no index to use, so it
        // read all 875,663 rows of fos_user every time. This guards the mapping; putting it on prod
        // is migrations/2026_09_user_github_id_idx.sql, which this cannot see.
        $indexes = self::getEM()->getConnection()->createSchemaManager()->listTableIndexes('fos_user');

        $indexedColumns = array_map(static fn ($index): array => $index->getColumns(), array_values($indexes));

        self::assertContains(['githubId'], $indexedColumns, 'fos_user needs an index on githubId');
    }

    public function testGetUsersQueryBuilderIncludesPackageCount(): void
    {
        $maintainer = self::createUser('withpkgs', 'withpkgs@example.org');
        $loner = self::createUser('nopkgs', 'nopkgs@example.org');
        $this->store($maintainer, $loner);
        $this->store(
            self::createPackage('acme/one', 'https://example.org/acme/one', maintainers: [$maintainer]),
            self::createPackage('acme/two', 'https://example.org/acme/two', maintainers: [$maintainer]),
        );

        $rows = $this->userRepository->getUsersQueryBuilder()->getQuery()->getResult();
        $countsByName = [];
        foreach ($rows as $row) {
            $countsByName[$row[0]->getUsername()] = (int) $row['packageCount'];
        }

        self::assertSame(2, $countsByName['withpkgs']);
        self::assertSame(0, $countsByName['nopkgs']);
    }

    public function testGetUsersQueryBuilderOrdersByFrozenAtWhenRequested(): void
    {
        $older = self::createUser('olderfreeze', 'older@example.org');
        $older->freeze(UserFreezeReason::Temporary);
        $newer = self::createUser('newerfreeze', 'newer@example.org');
        $newer->freeze(UserFreezeReason::Temporary);
        new \ReflectionProperty(User::class, 'frozenAt')->setValue($older, new \DateTimeImmutable('2026-01-01 00:00:00'));
        new \ReflectionProperty(User::class, 'frozenAt')->setValue($newer, new \DateTimeImmutable('2026-06-01 00:00:00'));
        $this->store($older, $newer);

        $rows = $this->userRepository->getUsersQueryBuilder(orderByFrozenAt: true)->getQuery()->getResult();
        $names = array_map(static fn (array $row): string => $row[0]->getUsername(), $rows);

        self::assertSame(['newerfreeze', 'olderfreeze'], $names);
    }

    public function testFindUsersByUsernameWithMultipleValidUsernames(): void
    {
        $alice = self::createUser('Alice', 'alice@example.org');
        $bob = self::createUser('Bob', 'bob@example.org');
        $charlie = self::createUser('Charlie', 'charlie@example.org');
        $john = self::createUser('John', 'john@example.org', enabled: false);
        $this->store($alice, $bob, $charlie, $john);

        $result = $this->userRepository->findEnabledUsersByUsername(['alice', 'bob', 'john']);

        $this->assertCount(2, $result);

        $this->assertArrayHasKey('alice', $result);
        $this->assertArrayHasKey('bob', $result);

        $this->assertSame($alice->getId(), $result['alice']->getId());
        $this->assertSame($bob->getId(), $result['bob']->getId());
    }
}
