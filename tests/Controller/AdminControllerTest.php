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

use App\Entity\AuditRecord;
use App\Entity\UserFreezeReason;
use App\Tests\IntegrationTestCase;

class AdminControllerTest extends IntegrationTestCase
{
    public function testIndexShowsRecentModerationActivity(): void
    {
        $admin = self::createUser('admin', 'admin@example.com', roles: ['ROLE_ADMIN']);
        $victim = self::createUser('victim', 'victim@example.org');
        $this->store($admin, $victim);

        self::getEM()->getRepository(AuditRecord::class)->insert(
            AuditRecord::userFrozen($victim, $admin, UserFreezeReason::Temporary, 'pending review')
        );

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/');

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('Recent moderation activity', $crawler->html());
        static::assertStringContainsString('victim', $crawler->filter('.audit-log-table')->text());
    }

    public function testIndexDeniedForRegularUser(): void
    {
        $user = self::createUser('plain', 'plain@example.com', roles: ['ROLE_USER']);
        $this->store($user);

        $this->client->loginUser($user);
        $this->client->request('GET', '/admin/');

        static::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testIndexAccessibleToFullAdmin(): void
    {
        $admin = self::createUser('admin', 'admin@example.com', roles: ['ROLE_ADMIN']);
        $this->store($admin);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/');

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('Filter lists', $crawler->html());
        static::assertStringContainsString('Suspect packages', $crawler->html());
        static::assertStringContainsString('Organizations', $crawler->html());
        static::assertStringContainsString('Transparency log', $crawler->html());
    }

    public function testIndexAccessibleToDelegatedCapabilityShowsOnlyPermittedTools(): void
    {
        // A user with only ROLE_DISABLE_PACKAGES can reach the admin section but should only see the
        // spam tooling, not filter-list administration (which needs ROLE_FILTER_LIST_ADMIN).
        $mod = self::createUser('pkgmod', 'pkgmod@example.com', roles: ['ROLE_DISABLE_PACKAGES']);
        $this->store($mod);

        $this->client->loginUser($mod);
        $crawler = $this->client->request('GET', '/admin/');

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('Suspect packages', $crawler->html());
        static::assertStringNotContainsString('Filter lists', $crawler->html());
    }
}
