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

use App\Audit\AuditRecordType;
use App\Entity\AuditRecord;
use App\Entity\FilterListEntry;
use App\FilterList\FilterLists;
use App\FilterList\FilterSources;
use App\FilterList\RemoteFilterListEntry;
use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\TestWith;

class AdminFilterListControllerTest extends IntegrationTestCase
{
    public function testIndexRequiresAdmin(): void
    {
        $user = self::createUser('non-admin', 'noadmin@example.com', roles: ['ROLE_USER']);
        $this->store($user);

        $this->client->loginUser($user);
        $this->client->request('GET', '/admin/filter-lists/');

        static::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testIndexShowsEntries(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = new FilterListEntry($this->createRemoteEntry('vendor/listed', '1.0.0'));
        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/');

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('vendor/listed', $crawler->html());
    }

    public function testIndexFiltersByPackageName(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $match = new FilterListEntry($this->createRemoteEntry('vendor/wanted', '1.0.0'));
        $other = new FilterListEntry($this->createRemoteEntry('vendor/other', '1.0.0'));
        $this->store($admin, $match, $other);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/?q=wanted');

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('vendor/wanted', $crawler->html());
        static::assertStringNotContainsString('vendor/other', $crawler->html());
    }

    public function testIndexFiltersByState(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $active = new FilterListEntry($this->createRemoteEntry('vendor/active', '1.0.0'));
        $disabled = new FilterListEntry($this->createRemoteEntry('vendor/disabled', '1.0.0'));
        $disabled->disable();
        $this->store($admin, $active, $disabled);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/?state=disabled');

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('vendor/disabled', $crawler->html());
        static::assertStringNotContainsString('vendor/active', $crawler->html());
    }

    #[TestWith(['list=bogus'])]
    #[TestWith(['source=bogus'])]
    public function testIndexRejectsUnknownValues(string $query): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $this->store($admin);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/filter-lists/?' . $query);

        static::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testDisableUpstreamEntryRecordsAuditAndHidesFromConsumers(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = new FilterListEntry($this->createRemoteEntry('vendor/false-positive', '1.0.0'));

        $this->store($admin, $entry);

        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/filter-lists/');
        $token = $crawler->filter('input[name="token"]')->first()->attr('value');
        static::assertNotEmpty($token);

        $this->client->request('POST', '/admin/filter-lists/'.$entry->getPublicId().'/disable', [
            'token' => $token,
        ]);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $em->clear();
        $refreshed = $em->getRepository(FilterListEntry::class)->findOneBy(['publicId' => $entry->getPublicId()]);
        static::assertNotNull($refreshed);
        static::assertTrue($refreshed->isDisabled());

        $audit = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditRecordType::FilterListEntryDisabled]);
        static::assertNotNull($audit);
        static::assertSame('vendor/false-positive', $audit->attributes['entry']['package_name']);

        $consumerEntries = $em->getRepository(FilterListEntry::class)->getPackageEntries('vendor/false-positive', FilterLists::MALWARE);
        static::assertSame([], $consumerEntries);
    }

    public function testEnableEntryRecordsAuditAndRestoresVisibility(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = new FilterListEntry($this->createRemoteEntry('vendor/restored', '1.0.0'));
        $entry->disable();

        $this->store($admin, $entry);

        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/filter-lists/?state=disabled');
        $token = $crawler->filter('input[name="token"]')->first()->attr('value');
        static::assertNotEmpty($token);

        $this->client->request('POST', '/admin/filter-lists/'.$entry->getPublicId().'/enable', [
            'token' => $token,
        ]);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $em->clear();
        $refreshed = $em->getRepository(FilterListEntry::class)->findOneBy(['publicId' => $entry->getPublicId()]);
        static::assertNotNull($refreshed);
        static::assertFalse($refreshed->isDisabled());

        $audit = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditRecordType::FilterListEntryEnabled]);
        static::assertNotNull($audit);
    }

    public function testEditUpstreamEntryStoresOverwriteVersionAndRecordsAudit(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = new FilterListEntry($this->createRemoteEntry('vendor/edit-me', '1.0.0'));

        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/'.$entry->getPublicId().'/edit');
        static::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $form['filter_list_entry[version]'] = '>=1.0,<2.0';
        $this->client->submit($form);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $em->clear();
        $refreshed = $em->getRepository(FilterListEntry::class)->findOneBy(['publicId' => $entry->getPublicId()]);
        static::assertNotNull($refreshed);
        static::assertSame('>=1.0,<2.0', $refreshed->getVersion(), 'getVersion() returns the admin overwrite.');
        static::assertSame('1.0.0', $refreshed->getRemoteVersion(), 'Upstream identity is preserved.');
        static::assertSame('>=1.0,<2.0', $refreshed->getOverwriteVersion(), 'Admin overwrite is stored separately.');
        static::assertSame('malware', $refreshed->getReason(), 'Upstream-supplied reason is preserved across an admin edit.');
        static::assertTrue($refreshed->isOverwritten());

        $audit = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditRecordType::FilterListEntryEdited]);
        static::assertNotNull($audit);
        static::assertSame('1.0.0', $audit->attributes['previous']['version']);
        static::assertSame('1.0.0', $audit->attributes['entry']['remote_version']);
        static::assertSame('>=1.0,<2.0', $audit->attributes['entry']['overwrite_version']);
    }

    public function testEditPageShowsAuditLogEntriesForThisFilterListEntry(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN', 'ROLE_AUDITOR']);
        $subject = new FilterListEntry($this->createRemoteEntry('vendor/audited', '1.0.0'));
        $unrelated = new FilterListEntry($this->createRemoteEntry('vendor/unrelated', '1.0.0'));

        $this->store($admin, $subject, $unrelated);

        $em = self::getEM();
        $em->persist(AuditRecord::filterListEntryDisabled($subject, $admin));
        $em->persist(AuditRecord::filterListEntryEnabled($subject, $admin));
        $em->persist(AuditRecord::filterListEntryDisabled($unrelated, $admin));
        $em->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/'.$subject->getPublicId().'/edit');

        static::assertResponseIsSuccessful();
        $auditSection = $crawler->filter('.audit-log-table')->html();
        static::assertStringContainsString('Entry added', $auditSection, 'Audit log must include the auto-recorded "added" event.');
        static::assertStringContainsString('Entry disabled', $auditSection);
        static::assertStringContainsString('Entry enabled', $auditSection);
        static::assertStringContainsString('vendor/audited', $auditSection);
        static::assertStringContainsString('by filter-admin', $auditSection, 'Audit log section must display which admin performed each action.');
        static::assertStringNotContainsString('vendor/unrelated', $auditSection, 'Audit log section must not leak entries from a different filter list entry.');
    }

    public function testEditClearsOverwriteWhenAdminRevertsToUpstreamVersion(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = new FilterListEntry($this->createRemoteEntry('vendor/revert', '1.0.0'));
        $entry->updateAttributes('2.0.0');
        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/'.$entry->getPublicId().'/edit');

        $form = $crawler->selectButton('Save')->form();
        $form['filter_list_entry[version]'] = '1.0.0';
        $this->client->submit($form);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $em->clear();
        $refreshed = $em->getRepository(FilterListEntry::class)->findOneBy(['publicId' => $entry->getPublicId()]);
        static::assertNotNull($refreshed);
        static::assertFalse($refreshed->isOverwritten(), 'Overwrite must be cleared when admin reverts to upstream value.');
        static::assertNull($refreshed->getOverwriteVersion());
        static::assertSame('1.0.0', $refreshed->getVersion());
    }

    public function testEditReturns404ForUnknownEntry(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $this->store($admin);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/filter-lists/PKFE-AAAA-BBBB-CCCC/edit');

        static::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testEditRejectsInvalidVersionConstraint(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = new FilterListEntry($this->createRemoteEntry('vendor/bad-edit', '1.0.0'));
        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/'.$entry->getPublicId().'/edit');

        $form = $crawler->selectButton('Save')->form();
        $form['filter_list_entry[version]'] = 'not-a-valid-constraint!@#';
        $crawler = $this->client->submit($form);

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('Invalid version constraint', $crawler->html());

        $em = self::getEM();
        $em->clear();
        $refreshed = $em->getRepository(FilterListEntry::class)->findOneBy(['publicId' => $entry->getPublicId()]);
        static::assertSame('1.0.0', $refreshed?->getVersion(), 'Version must not be updated when the constraint is invalid.');
    }

    public function testDisableRequiresCsrfToken(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = new FilterListEntry($this->createRemoteEntry('vendor/csrf', '1.0.0'));
        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $this->client->request('POST', '/admin/filter-lists/'.$entry->getPublicId().'/disable', [
            'token' => 'bogus',
        ]);

        static::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testEnableRequiresCsrfToken(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = new FilterListEntry($this->createRemoteEntry('vendor/csrf-enable', '1.0.0'));
        $entry->disable();
        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $this->client->request('POST', '/admin/filter-lists/'.$entry->getPublicId().'/enable', [
            'token' => 'bogus',
        ]);

        static::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testDisableAlreadyDisabledIsIdempotent(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = new FilterListEntry($this->createRemoteEntry('vendor/already-disabled', '1.0.0'));
        $entry->disable();
        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/?state=disabled');
        $token = $crawler->filter('input[name="token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/filter-lists/'.$entry->getPublicId().'/disable', [
            'token' => $token,
        ]);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $audits = $em->getRepository(AuditRecord::class)->findBy(['type' => AuditRecordType::FilterListEntryDisabled]);
        static::assertCount(0, $audits);
    }

    public function testEnableAlreadyEnabledIsIdempotent(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = new FilterListEntry($this->createRemoteEntry('vendor/already-enabled', '1.0.0'));
        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/');
        $token = $crawler->filter('input[name="token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/filter-lists/'.$entry->getPublicId().'/enable', [
            'token' => $token,
        ]);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $audits = $em->getRepository(AuditRecord::class)->findBy(['type' => AuditRecordType::FilterListEntryEnabled]);
        static::assertCount(0, $audits);
    }

    public function testDisableMarksPackageStaleForRedump(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $package = self::createPackage('vendor/stale', 'https://example.com/vendor/stale');
        $entry = new FilterListEntry($this->createRemoteEntry('vendor/stale', '1.0.0'));
        $this->store($admin, $package, $entry);

        $em = self::getEM();
        $em->getConnection()->executeStatement('UPDATE package SET dumpedAtV2 = NOW() WHERE name = :name', ['name' => 'vendor/stale']);
        $em->clear();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/');
        $token = $crawler->filter('input[name="token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/filter-lists/'.$entry->getPublicId().'/disable', [
            'token' => $token,
        ]);

        static::assertResponseRedirects('/admin/filter-lists/');

        $dumpedAt = $em->getConnection()->fetchOne('SELECT dumpedAtV2 FROM package WHERE name = :name', ['name' => 'vendor/stale']);
        static::assertNull($dumpedAt, 'Package must be marked for re-dump when its filter list entry is disabled.');
    }

    public function testEditMarksPackageStaleForRedump(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $package = self::createPackage('vendor/stale-edit', 'https://example.com/vendor/stale-edit');
        $entry = new FilterListEntry($this->createRemoteEntry('vendor/stale-edit', '1.0.0'));
        $this->store($admin, $package, $entry);

        $em = self::getEM();
        $em->getConnection()->executeStatement('UPDATE package SET dumpedAtV2 = NOW() WHERE name = :name', ['name' => 'vendor/stale-edit']);
        $em->clear();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/'.$entry->getPublicId().'/edit');
        $form = $crawler->selectButton('Save')->form();
        $form['filter_list_entry[version]'] = '>=1.0,<2.0';
        $this->client->submit($form);

        static::assertResponseRedirects('/admin/filter-lists/');

        $dumpedAt = $em->getConnection()->fetchOne('SELECT dumpedAtV2 FROM package WHERE name = :name', ['name' => 'vendor/stale-edit']);
        static::assertNull($dumpedAt, 'Package must be marked for re-dump when its filter list entry is edited.');
    }

    public function testEnableMarksPackageStaleForRedump(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $package = self::createPackage('vendor/stale-enable', 'https://example.com/vendor/stale-enable');
        $entry = new FilterListEntry($this->createRemoteEntry('vendor/stale-enable', '1.0.0'));
        $entry->disable();
        $this->store($admin, $package, $entry);

        $em = self::getEM();
        $em->getConnection()->executeStatement('UPDATE package SET dumpedAtV2 = NOW() WHERE name = :name', ['name' => 'vendor/stale-enable']);
        $em->clear();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/?state=disabled');
        $token = $crawler->filter('input[name="token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/filter-lists/'.$entry->getPublicId().'/enable', [
            'token' => $token,
        ]);

        static::assertResponseRedirects('/admin/filter-lists/');

        $dumpedAt = $em->getConnection()->fetchOne('SELECT dumpedAtV2 FROM package WHERE name = :name', ['name' => 'vendor/stale-enable']);
        static::assertNull($dumpedAt, 'Package must be marked for re-dump when its filter list entry is re-enabled.');
    }

    private function createRemoteEntry(string $packageName, string $version): RemoteFilterListEntry
    {
        return new RemoteFilterListEntry(
            $packageName,
            $version,
            FilterLists::MALWARE,
            'https://example.com/'.$packageName,
            'malware',
            FilterSources::AIKIDO,
        );
    }
}
