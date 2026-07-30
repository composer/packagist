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

namespace App\Tests\Controller;

use App\Entity\UserFreezeReason;
use App\Tests\IntegrationTestCase;

class FrozenUserControllerTest extends IntegrationTestCase
{
    public function testDeniedWithoutDisableUsersRole(): void
    {
        // ROLE_DISABLE_PACKAGES can reach /admin/ but must not see the frozen-users page.
        $mod = self::createUser('pkgmod', 'pkgmod@example.org', roles: ['ROLE_DISABLE_PACKAGES']);
        $this->store($mod);

        $this->client->loginUser($mod);
        $this->client->request('GET', '/admin/frozen-users');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testListsFrozenUsersAndFiltersByReason(): void
    {
        $mod = self::createUser('mod', 'mod@example.org', roles: ['ROLE_DISABLE_USERS']);
        $spammer = self::createUser('spammer', 'spammer@example.org');
        $spammer->freeze(UserFreezeReason::Spam);
        $temp = self::createUser('temphold', 'temp@example.org');
        $temp->freeze(UserFreezeReason::Temporary);
        $this->store($mod, $spammer, $temp);

        $this->client->loginUser($mod);

        // Default view is the Temporary review queue.
        $default = $this->client->request('GET', '/admin/frozen-users')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('temphold', $default);
        self::assertStringNotContainsString('spammer', $default);

        // "All" (explicit empty reason) lists every frozen account.
        $all = $this->client->request('GET', '/admin/frozen-users?reason=')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spammer', $all);
        self::assertStringContainsString('temphold', $all);

        // Filtering to spam shows only spam-frozen accounts.
        $spamOnly = $this->client->request('GET', '/admin/frozen-users?reason=spam')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spammer', $spamOnly);
        self::assertStringNotContainsString('temphold', $spamOnly);
    }
}
