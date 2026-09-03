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

namespace App\Tests\Controller\Admin;

use App\Entity\UserFreezeReason;
use App\Tests\IntegrationTestCase;

class UserControllerTest extends IntegrationTestCase
{
    public function testDeniedWithoutDisableUsersRole(): void
    {
        // ROLE_DISABLE_PACKAGES can reach /admin/ but must not see the user directory.
        $mod = self::createUser('pkgmod', 'pkgmod@example.org', roles: ['ROLE_DISABLE_PACKAGES']);
        $this->store($mod);

        $this->client->loginUser($mod);
        $this->client->request('GET', '/admin/users');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testListsUsersWithPackageCountAndProfileLink(): void
    {
        $mod = self::createUser('mod', 'mod@example.org', roles: ['ROLE_DISABLE_USERS']);
        $active = self::createUser('activeuser', 'active@example.org');
        $this->store($mod, $active);

        $this->client->loginUser($mod);

        $html = $this->client->request('GET', '/admin/users')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('activeuser', $html);
        self::assertStringContainsString('/users/activeuser/', $html);
    }

    public function testSearchFiltersByUsernameOrEmail(): void
    {
        $mod = self::createUser('mod2', 'mod2@example.org', roles: ['ROLE_DISABLE_USERS']);
        $alice = self::createUser('alice', 'alice@findme.example.org');
        $bob = self::createUser('bob', 'bob@example.org');
        $this->store($mod, $alice, $bob);

        $this->client->loginUser($mod);

        $byUsername = $this->client->request('GET', '/admin/users?search=alice')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('>alice<', $byUsername);
        self::assertStringNotContainsString('>bob<', $byUsername);

        $byEmail = $this->client->request('GET', '/admin/users?search=findme')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('>alice<', $byEmail);
        self::assertStringNotContainsString('>bob<', $byEmail);
    }

    public function testSearchIntersectsWithOtherFilters(): void
    {
        $mod = self::createUser('mod6', 'mod6@example.org', roles: ['ROLE_DISABLE_USERS']);
        $frozenMatch = self::createUser('needle-frozen', 'needle-frozen@example.org');
        $frozenMatch->freeze(UserFreezeReason::Temporary);
        $activeMatch = self::createUser('needle-active', 'needle-active@example.org');
        $this->store($mod, $frozenMatch, $activeMatch);

        $this->client->loginUser($mod);

        $html = $this->client->request('GET', '/admin/users?search=needle&frozen=any')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('>needle-frozen<', $html);
        self::assertStringNotContainsString('>needle-active<', $html);
    }

    public function testUnknownFilterValuesAreRejected(): void
    {
        $mod = self::createUser('modx', 'modx@example.org', roles: ['ROLE_DISABLE_USERS']);
        $this->store($mod);
        $this->client->loginUser($mod);

        foreach (['frozen=frozen', 'twofa=on', 'github_linked=true', 'registered_from=2026-02-31', 'registered_to=01/2026'] as $query) {
            $this->client->request('GET', '/admin/users?'.$query);
            self::assertSame(400, $this->client->getResponse()->getStatusCode(), $query);
        }
    }

    public function testFrozenUsersRouteShowsTheTemporaryHoldQueue(): void
    {
        $mod = self::createUser('mody', 'mody@example.org', roles: ['ROLE_DISABLE_USERS']);
        $temp = self::createUser('tempheld', 'tempheld@example.org');
        $temp->freeze(UserFreezeReason::Temporary);
        $spammer = self::createUser('spamheld', 'spamheld@example.org');
        $spammer->freeze(UserFreezeReason::Spam);
        $this->store($mod, $temp, $spammer);
        $this->client->loginUser($mod);

        $crawler = $this->client->request('GET', '/admin/frozen-users');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Frozen users', $crawler->filter('h2.title')->text(normalizeWhitespace: true));
        $html = $crawler->html();
        self::assertStringContainsString('>tempheld<', $html);
        self::assertStringNotContainsString('>spamheld<', $html);
        self::assertStringContainsString('Frozen at', $html);
        self::assertSame('temporary', $crawler->filter('#frozen-filter option[selected]')->attr('value'));
    }

    public function testMenuHighlightMatchesTheRoute(): void
    {
        $mod = self::createUser('modm', 'modm@example.org', roles: ['ROLE_DISABLE_USERS']);
        $this->store($mod);
        $this->client->loginUser($mod);

        $onUsers = $this->client->request('GET', '/admin/users')->filter('.admin-nav .active')->text(normalizeWhitespace: true);
        self::assertStringContainsString('Users', $onUsers);
        self::assertStringNotContainsString('Frozen users', $onUsers);

        $onFrozen = $this->client->request('GET', '/admin/frozen-users')->filter('.admin-nav .active')->text(normalizeWhitespace: true);
        self::assertStringContainsString('Frozen users', $onFrozen);
    }

    public function testFreezeFilterReplacesOldFrozenUsersQueue(): void
    {
        $mod = self::createUser('mod3', 'mod3@example.org', roles: ['ROLE_DISABLE_USERS']);
        $spammer = self::createUser('spammer', 'spammer@example.org');
        $spammer->freeze(UserFreezeReason::Spam);
        $temp = self::createUser('temphold', 'temp@example.org');
        $temp->freeze(UserFreezeReason::Temporary);
        $active = self::createUser('activeacct', 'activeacct@example.org');
        $this->store($mod, $spammer, $temp, $active);

        $this->client->loginUser($mod);

        // Default view lists everyone.
        $all = $this->client->request('GET', '/admin/users')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spammer', $all);
        self::assertStringContainsString('temphold', $all);
        self::assertStringContainsString('activeacct', $all);

        // "Frozen (any reason)" narrows to frozen accounts only.
        $anyFrozen = $this->client->request('GET', '/admin/users?frozen=any')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spammer', $anyFrozen);
        self::assertStringContainsString('temphold', $anyFrozen);
        self::assertStringNotContainsString('activeacct', $anyFrozen);

        // Filtering to a specific reason mirrors the old dedicated queue.
        $spamOnly = $this->client->request('GET', '/admin/users?frozen=spam')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spammer', $spamOnly);
        self::assertStringNotContainsString('temphold', $spamOnly);
    }

    public function testTwoFactorAndGithubFilters(): void
    {
        $mod = self::createUser('mod4', 'mod4@example.org', roles: ['ROLE_DISABLE_USERS']);
        $secured = self::createUser('secured', 'secured@example.org', githubId: '777001');
        $secured->setTotpSecret('SECRET');
        $plain = self::createUser('plainacct', 'plainacct@example.org');
        $plain->setGithubId(null);
        $this->store($mod, $secured, $plain);

        $this->client->loginUser($mod);

        $twoFaEnabled = $this->client->request('GET', '/admin/users?twofa=enabled')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('secured', $twoFaEnabled);
        self::assertStringNotContainsString('plainacct', $twoFaEnabled);

        $notLinked = $this->client->request('GET', '/admin/users?github_linked=no')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('plainacct', $notLinked);
        self::assertStringNotContainsString('>secured<', $notLinked);

        $byGithubId = $this->client->request('GET', '/admin/users?github_id=777001')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('secured', $byGithubId);
        self::assertStringNotContainsString('plainacct', $byGithubId);
    }

    public function testRegistrationDateFilter(): void
    {
        $mod = self::createUser('mod5', 'mod5@example.org', roles: ['ROLE_DISABLE_USERS']);
        $old = self::createUser('oldreg', 'oldreg@example.org');
        $recent = self::createUser('recentreg', 'recentreg@example.org');
        new \ReflectionProperty(\App\Entity\User::class, 'createdAt')->setValue($old, new \DateTimeImmutable('2020-01-01 12:00:00'));
        new \ReflectionProperty(\App\Entity\User::class, 'createdAt')->setValue($recent, new \DateTimeImmutable('2026-06-15 12:00:00'));
        $this->store($mod, $old, $recent);

        $this->client->loginUser($mod);

        $since2025 = $this->client->request('GET', '/admin/users?registered_from=2025-01-01')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('recentreg', $since2025);
        self::assertStringNotContainsString('oldreg', $since2025);
    }

    public function testFreezeContextColumnsAppearOnlyForFrozenFilter(): void
    {
        $mod = self::createUser('modz', 'modz@example.org', roles: ['ROLE_DISABLE_USERS']);
        $frozen = self::createUser('heldacct', 'held@example.org');
        $frozen->freeze(UserFreezeReason::Temporary);
        $this->store($mod, $frozen);

        $this->client->loginUser($mod);

        $default = $this->client->request('GET', '/admin/users')->html();
        self::assertStringNotContainsString('Frozen at', $default);

        $frozenView = $this->client->request('GET', '/admin/users?frozen=temporary')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Frozen at', $frozenView);
        self::assertStringContainsString('Frozen by', $frozenView);
        self::assertStringContainsString('heldacct', $frozenView);
    }
}
