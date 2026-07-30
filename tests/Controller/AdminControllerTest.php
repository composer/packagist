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

use App\Audit\VersionDeletionReason;
use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\UserFreezeReason;
use App\Entity\Version;
use App\Tests\IntegrationTestCase;

class AdminControllerTest extends IntegrationTestCase
{
    public function testIndexModerationFeedExcludesNonAdminVersionActions(): void
    {
        $admin = self::createUser('admin', 'admin@example.com', roles: ['ROLE_ADMIN']);
        $adminPkg = self::createPackage('vendor/adminpkg', 'https://example.org/adminpkg');
        $maintPkg = self::createPackage('vendor/maintpkg', 'https://example.org/maintpkg');
        $autoPkg = self::createPackage('vendor/autopkg', 'https://example.org/autopkg');
        $this->store($admin, $adminPkg, $maintPkg, $autoPkg);

        $version = static function (Package $package): Version {
            $v = new Version();
            $v->setPackage($package);
            $v->setName($package->getName());
            $v->setVersion('1.0.0');
            $v->setNormalizedVersion('1.0.0.0');

            return $v;
        };

        $repo = self::getEM()->getRepository(AuditRecord::class);
        // Admin takedown — should appear.
        $repo->insert(AuditRecord::versionSoftDeleted($version($adminPkg), VersionDeletionReason::Hidden, null, null, $admin));
        // Maintainer pull and the Updater's auto missing-version handling — must not appear.
        $repo->insert(AuditRecord::versionSoftDeleted($version($maintPkg), VersionDeletionReason::DeletedByMaintainer, null, null, null));
        $repo->insert(AuditRecord::versionRecovered($version($autoPkg), VersionDeletionReason::AutoDeletedMissing, null));

        $this->client->loginUser($admin);
        $feed = $this->client->request('GET', '/admin/')->filter('.audit-log-table')->text();

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('vendor/adminpkg', $feed);
        static::assertStringNotContainsString('vendor/maintpkg', $feed);
        static::assertStringNotContainsString('vendor/autopkg', $feed);
    }

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
