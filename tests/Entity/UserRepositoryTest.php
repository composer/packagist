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

    /**
     * @return list<string>
     */
    private function usernamesFromRows(array $rows): array
    {
        return array_map(static fn (array $row): string => $row[0]->getUsername(), $rows);
    }

    public function testGetUsersQueryBuilderFiltersByFreezeStatus(): void
    {
        $spammer = self::createUser('spammer', 'spammer@example.org');
        $spammer->freeze(UserFreezeReason::Spam);
        $temp = self::createUser('temphold', 'temp@example.org');
        $temp->freeze(UserFreezeReason::Temporary);
        $active = self::createUser('active', 'active@example.org');
        $this->store($spammer, $temp, $active);

        $all = $this->usernamesFromRows($this->userRepository->getUsersQueryBuilder()->getQuery()->getResult());
        self::assertContains('spammer', $all);
        self::assertContains('temphold', $all);
        self::assertContains('active', $all);

        $anyFrozen = $this->usernamesFromRows($this->userRepository->getUsersQueryBuilder(frozenFilter: 'any')->getQuery()->getResult());
        self::assertContains('spammer', $anyFrozen);
        self::assertContains('temphold', $anyFrozen);
        self::assertNotContains('active', $anyFrozen, 'unfrozen accounts must not appear');

        $notFrozen = $this->usernamesFromRows($this->userRepository->getUsersQueryBuilder(frozenFilter: 'none')->getQuery()->getResult());
        self::assertSame(['active'], $notFrozen);

        $temporaryOnly = $this->usernamesFromRows($this->userRepository->getUsersQueryBuilder(frozenFilter: 'temporary')->getQuery()->getResult());
        self::assertSame(['temphold'], $temporaryOnly);
    }

    public function testGetUsersQueryBuilderFiltersBySearchTerm(): void
    {
        $alice = self::createUser('alice', 'alice@example.org');
        $bob = self::createUser('bob', 'bob@findme.example.org');
        $this->store($alice, $bob);

        $byUsername = $this->usernamesFromRows($this->userRepository->getUsersQueryBuilder(search: 'ali')->getQuery()->getResult());
        self::assertSame(['alice'], $byUsername);

        $byEmail = $this->usernamesFromRows($this->userRepository->getUsersQueryBuilder(search: 'findme')->getQuery()->getResult());
        self::assertSame(['bob'], $byEmail);
    }

    public function testGetUsersQueryBuilderIncludesPackageCount(): void
    {
        $user = self::createUser('withpkgs', 'withpkgs@example.org');
        $this->store($user);

        $rows = $this->userRepository->getUsersQueryBuilder(search: 'withpkgs')->getQuery()->getResult();
        self::assertCount(1, $rows);
        self::assertSame(0, (int) $rows[0]['packageCount']);
    }

    public function testGetUsersQueryBuilderFiltersByRegistrationDateRange(): void
    {
        $old = self::createUser('oldaccount', 'old@example.org');
        $recent = self::createUser('recentaccount', 'recent@example.org');
        new \ReflectionProperty(User::class, 'createdAt')->setValue($old, new \DateTimeImmutable('2020-01-01 12:00:00'));
        new \ReflectionProperty(User::class, 'createdAt')->setValue($recent, new \DateTimeImmutable('2026-06-15 12:00:00'));
        $this->store($old, $recent);

        $from = $this->usernamesFromRows($this->userRepository->getUsersQueryBuilder(registeredFrom: new \DateTimeImmutable('2025-01-01 00:00:00'))->getQuery()->getResult());
        self::assertSame(['recentaccount'], $from);

        $to = $this->usernamesFromRows($this->userRepository->getUsersQueryBuilder(registeredTo: new \DateTimeImmutable('2025-01-01 00:00:00'))->getQuery()->getResult());
        self::assertSame(['oldaccount'], $to);

        $between = $this->usernamesFromRows($this->userRepository->getUsersQueryBuilder(
            registeredFrom: new \DateTimeImmutable('2019-01-01 00:00:00'),
            registeredTo: new \DateTimeImmutable('2021-01-01 00:00:00'),
        )->getQuery()->getResult());
        self::assertSame(['oldaccount'], $between);
    }

    public function testGetUsersQueryBuilderFiltersByTwoFactorStatus(): void
    {
        $withTwoFa = self::createUser('with2fa', 'with2fa@example.org');
        $withTwoFa->setTotpSecret('SECRET');
        $withoutTwoFa = self::createUser('without2fa', 'without2fa@example.org');
        $this->store($withTwoFa, $withoutTwoFa);

        $enabled = $this->usernamesFromRows($this->userRepository->getUsersQueryBuilder(twoFactorFilter: 'enabled')->getQuery()->getResult());
        self::assertSame(['with2fa'], $enabled);

        $disabled = $this->usernamesFromRows($this->userRepository->getUsersQueryBuilder(twoFactorFilter: 'disabled')->getQuery()->getResult());
        self::assertSame(['without2fa'], $disabled);
    }

    public function testGetUsersQueryBuilderFiltersByGithubIdAndLinkStatus(): void
    {
        $linked = self::createUser('linkeduser', 'linked@example.org', githubId: '424242');
        $unlinked = self::createUser('unlinkeduser', 'unlinked@example.org');
        $unlinked->setGithubId(null);
        $this->store($linked, $unlinked);

        $byId = $this->usernamesFromRows($this->userRepository->getUsersQueryBuilder(githubId: '424242')->getQuery()->getResult());
        self::assertSame(['linkeduser'], $byId);

        $onlyLinked = $this->usernamesFromRows($this->userRepository->getUsersQueryBuilder(githubLinkedFilter: 'yes')->getQuery()->getResult());
        self::assertSame(['linkeduser'], $onlyLinked);

        $onlyUnlinked = $this->usernamesFromRows($this->userRepository->getUsersQueryBuilder(githubLinkedFilter: 'no')->getQuery()->getResult());
        self::assertSame(['unlinkeduser'], $onlyUnlinked);
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
