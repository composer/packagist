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
use App\Entity\FilterListEntry;
use App\FilterList\FilterLists;
use App\FilterList\FilterSources;
use App\FilterList\RemoteFilterListEntry;
use App\Form\Model\FilterListEntryRequest;
use App\Log\AuditLogEventType;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\ArrayParameterType;
use PHPUnit\Framework\Attributes\TestWith;

class FilterListControllerTest extends IntegrationTestCase
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
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/listed', '1.0.0'));
        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/');

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('vendor/listed', $crawler->html());
    }

    public function testIndexFiltersByPackageName(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $match = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/wanted', '1.0.0'));
        $other = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/other', '1.0.0'));
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
        $active = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/active', '1.0.0'));
        $disabled = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/disabled', '1.0.0'));
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
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/false-positive', '1.0.0'));

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

        $audit = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditLogEventType::FilterListEntryDisabled]);
        static::assertNotNull($audit);
        static::assertSame('vendor/false-positive', $audit->attributes['entry']['package_name']);

        $consumerEntries = $em->getRepository(FilterListEntry::class)->getPackageEntries('vendor/false-positive', FilterLists::MALWARE);
        static::assertSame([], $consumerEntries);
    }

    public function testEnableEntryRecordsAuditAndRestoresVisibility(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/restored', '1.0.0'));
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

        $audit = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditLogEventType::FilterListEntryEnabled]);
        static::assertNotNull($audit);
    }

    public function testEditUpstreamEntryStoresOverwriteVersionAndRecordsAudit(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/edit-me', '1.0.0'));

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

        $audit = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditLogEventType::FilterListEntryEdited]);
        static::assertNotNull($audit);
        static::assertSame('1.0.0', $audit->attributes['previous']['version']);
        static::assertSame('1.0.0', $audit->attributes['entry']['remote_version']);
        static::assertSame('>=1.0,<2.0', $audit->attributes['entry']['overwrite_version']);
    }

    public function testEditPageShowsAuditLogEntriesForThisFilterListEntry(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN', 'ROLE_AUDITOR']);
        $subject = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/audited', '1.0.0'));
        $unrelated = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/unrelated', '1.0.0'));

        $this->store($admin, $subject, $unrelated);

        $em = self::getEM();
        $em->persist(AuditRecord::filterListEntryDisabled($subject, $admin, null));
        $em->persist(AuditRecord::filterListEntryEnabled($subject, $admin, null));
        $em->persist(AuditRecord::filterListEntryDisabled($unrelated, $admin, null));
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

    public function testEditStoresInternalNoteAndRecordsPreviousValueInAudit(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/note-me', '1.0.0'));
        $entry->updateAttributes('1.0.0', 'initial note');

        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/'.$entry->getPublicId().'/edit');
        static::assertResponseIsSuccessful();
        static::assertStringContainsString('visible to filter list admins only', $crawler->html(), 'The internal note help text must be rendered.');

        $form = $crawler->selectButton('Save')->form();
        static::assertSame('initial note', $form['filter_list_entry[internalNote]']->getValue(), 'Existing internal note must pre-fill the form.');
        $form['filter_list_entry[internalNote]'] = 'updated note';
        $this->client->submit($form);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $em->clear();
        $refreshed = $em->getRepository(FilterListEntry::class)->findOneBy(['publicId' => $entry->getPublicId()]);
        static::assertNotNull($refreshed);
        static::assertSame('updated note', $refreshed->getInternalNote());

        $audit = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditLogEventType::FilterListEntryEdited]);
        static::assertNotNull($audit);
        static::assertSame('updated note', $audit->attributes['entry']['internal_note']);
        static::assertSame('initial note', $audit->attributes['previous']['internal_note']);
    }

    public function testEditClearsInternalNoteWhenLeftEmpty(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/clear-note', '1.0.0'));
        $entry->updateAttributes('1.0.0', 'some note');

        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/'.$entry->getPublicId().'/edit');

        $form = $crawler->selectButton('Save')->form();
        $form['filter_list_entry[internalNote]'] = '';
        $this->client->submit($form);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $em->clear();
        $refreshed = $em->getRepository(FilterListEntry::class)->findOneBy(['publicId' => $entry->getPublicId()]);
        static::assertNull($refreshed?->getInternalNote(), 'Empty submission must clear the internal note rather than store an empty string.');
    }

    public function testEditClearsOverwriteWhenAdminRevertsToUpstreamVersion(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/revert', '1.0.0'));
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

    public function testNewCreatesManualEntryAndRecordsAddedAudit(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $package = self::createPackage('vendor/manual-new', 'https://example.com/vendor/manual-new');
        $this->store($admin, $package);

        $em = self::getEM();
        $em->getConnection()->executeStatement('UPDATE package SET dumpedAtV2 = NOW() WHERE name = :name', ['name' => 'vendor/manual-new']);
        $em->clear();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/new');
        static::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create')->form();
        $form['filter_list_entry_bulk[packages]'] = 'vendor/manual-new 1.2.3';
        $form['filter_list_entry_bulk[list]'] = FilterLists::MALWARE->value;
        $form['filter_list_entry_bulk[reason]'] = 'Reported internally';
        $form['filter_list_entry_bulk[link]'] = 'https://example.com/report';
        $form['filter_list_entry_bulk[internalNote]'] = 'Flagged by the abuse team';
        $this->client->submit($form);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $em->clear();
        $created = $em->getRepository(FilterListEntry::class)->findOneBy(['packageName' => 'vendor/manual-new']);
        static::assertNotNull($created);
        static::assertTrue($created->isManual());
        static::assertSame(FilterSources::PACKAGIST, $created->getSource());
        static::assertSame('1.2.3', $created->getVersion());
        static::assertSame('1.2.3', $created->getRemoteVersion(), 'Manual entries store the version directly, not as an overwrite.');
        static::assertNull($created->getOverwriteVersion());
        static::assertFalse($created->isOverwritten());
        static::assertSame('Reported internally', $created->getReason());
        static::assertSame('Flagged by the abuse team', $created->getInternalNote());

        $audit = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditLogEventType::FilterListEntryAdded]);
        static::assertNotNull($audit);
        static::assertSame('vendor/manual-new', $audit->attributes['entry']['package_name']);
        static::assertSame('packagist', $audit->attributes['entry']['source']);
        static::assertSame('filter-admin', $audit->attributes['actor']['username'], 'The admin who added the manual entry must be recorded as the author.');
        static::assertSame($admin->getId(), $audit->actorId, 'The added audit record must carry the actor id like the other filter-list events.');

        $dumpedAt = $em->getConnection()->fetchOne('SELECT dumpedAtV2 FROM package WHERE name = :name', ['name' => 'vendor/manual-new']);
        static::assertNull($dumpedAt, 'Package must be marked for re-dump when a manual entry is created.');
    }

    public function testNewRejectsDuplicateSlot(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        // A manual entry has the same source (Packagist) the create form uses, so
        // the new submission lands on the exact same unique slot.
        $existing = $this->createManualEntry(FilterLists::MALWARE, 'vendor/dup', '1.0.0', 'reason', null);
        $this->store($admin, $existing);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/new');

        $form = $crawler->selectButton('Create')->form();
        $form['filter_list_entry_bulk[packages]'] = 'vendor/dup 1.0.0';
        $form['filter_list_entry_bulk[list]'] = FilterLists::MALWARE->value;
        $crawler = $this->client->submit($form);

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('already exists', $crawler->html());

        $em = self::getEM();
        $count = $em->getRepository(FilterListEntry::class)->count(['packageName' => 'vendor/dup']);
        static::assertSame(1, $count, 'No second entry must be created for a duplicate slot.');
    }

    public function testNewAllowsSameSlotFromDifferentSource(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        // An upstream (Aikido) entry occupies the slot for one source; a manual
        // (Packagist) entry for the same list/package/version is a different slot.
        $existing = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/multi-source', '1.0.0'));
        $this->store($admin, $existing);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/new');

        $form = $crawler->selectButton('Create')->form();
        $form['filter_list_entry_bulk[packages]'] = 'vendor/multi-source 1.0.0';
        $form['filter_list_entry_bulk[list]'] = FilterLists::MALWARE->value;
        $this->client->submit($form);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $em->clear();
        $entries = $em->getRepository(FilterListEntry::class)->findBy(['packageName' => 'vendor/multi-source']);
        static::assertCount(2, $entries, 'The same slot must be allowed once per source.');

        $sources = array_map(static fn (FilterListEntry $entry) => $entry->getSource(), $entries);
        static::assertContains(FilterSources::AIKIDO, $sources);
        static::assertContains(FilterSources::PACKAGIST, $sources);
    }

    public function testNewCreatesEveryLineAndAppliesTheSharedFieldsToEachOfThem(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $names = ['vendor/bulk-one', 'vendor/bulk-two', 'vendor/bulk-three'];
        $packages = array_map(static fn (string $name) => self::createPackage($name, 'https://example.com/'.$name), $names);
        $this->store($admin, ...$packages);

        $em = self::getEM();
        $em->getConnection()->executeStatement('UPDATE package SET dumpedAtV2 = NOW() WHERE name IN (:names)', ['names' => $names], ['names' => ArrayParameterType::STRING]);
        $em->clear();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/new');
        static::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create')->form();
        $form['filter_list_entry_bulk[packages]'] = "vendor/bulk-one 1.0.0\nvendor/bulk-two 2.0.0\nvendor/bulk-three ^3.1";
        $form['filter_list_entry_bulk[list]'] = FilterLists::MALWARE->value;
        $form['filter_list_entry_bulk[reason]'] = 'Same abuse report';
        $form['filter_list_entry_bulk[link]'] = 'https://example.com/report';
        $form['filter_list_entry_bulk[internalNote]'] = 'Flagged by the abuse team';
        $this->client->submit($form);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $em->clear();

        $expectedVersions = ['vendor/bulk-one' => '1.0.0', 'vendor/bulk-two' => '2.0.0', 'vendor/bulk-three' => '^3.1'];
        foreach ($expectedVersions as $name => $version) {
            $created = $em->getRepository(FilterListEntry::class)->findOneBy(['packageName' => $name]);
            static::assertNotNull($created, $name.' must have been created from its line.');
            static::assertTrue($created->isManual());
            static::assertSame(FilterSources::PACKAGIST, $created->getSource());
            static::assertSame($version, $created->getVersion());
            static::assertNull($created->getOverwriteVersion());
            static::assertSame(FilterLists::MALWARE, $created->getList());
            static::assertSame('Same abuse report', $created->getReason(), 'The shared reason must be applied to every line.');
            static::assertSame('https://example.com/report', $created->getLink(), 'The shared link must be applied to every line.');
            static::assertSame('Flagged by the abuse team', $created->getInternalNote(), 'The shared internal note must be applied to every line.');
        }

        $audits = $em->getRepository(AuditRecord::class)->findBy(['type' => AuditLogEventType::FilterListEntryAdded]);
        static::assertCount(3, $audits, 'Each created entry must get its own added audit record.');
        $auditedNames = array_map(static fn (AuditRecord $audit) => $audit->attributes['entry']['package_name'], $audits);
        static::assertEqualsCanonicalizing($names, $auditedNames);
        foreach ($audits as $audit) {
            static::assertSame('filter-admin', $audit->attributes['actor']['username'], 'The admin who pasted the batch must be recorded on every entry.');
        }

        $stillDumped = $em->getConnection()->fetchOne('SELECT count(*) FROM package WHERE name IN (:names) AND dumpedAtV2 IS NOT NULL', ['names' => $names], ['names' => ArrayParameterType::STRING]);
        static::assertSame(0, (int) $stillDumped, 'Every package touched by the batch must be marked for re-dump.');
    }

    public function testNewKeepsVersionConstraintsContainingSpacesIntact(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $this->store($admin);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/new');

        // Only the first run of whitespace separates name from constraint.
        $form = $crawler->selectButton('Create')->form();
        $form['filter_list_entry_bulk[packages]'] = 'vendor/spaced >=1.0 <2.0';
        $form['filter_list_entry_bulk[list]'] = FilterLists::MALWARE->value;
        $this->client->submit($form);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $em->clear();
        $created = $em->getRepository(FilterListEntry::class)->findOneBy(['packageName' => 'vendor/spaced']);
        static::assertNotNull($created);
        static::assertSame('>=1.0 <2.0', $created->getVersion(), 'A multi-token constraint must be stored verbatim.');
    }

    public function testNewIgnoresBlankAndWhitespaceOnlyLines(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $this->store($admin);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/new');

        $form = $crawler->selectButton('Create')->form();
        $form['filter_list_entry_bulk[packages]'] = "\nvendor/blank-one 1.0.0\n   \n\n  vendor/blank-two 2.0.0  \n\n";
        $form['filter_list_entry_bulk[list]'] = FilterLists::MALWARE->value;
        $this->client->submit($form);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $em->clear();
        $entries = $em->getRepository(FilterListEntry::class)->findAll();
        static::assertCount(2, $entries, 'Blank and whitespace-only lines must not produce entries or errors.');
    }

    public function testNewRejectsTheWholeBatchWhenOneLineHasAnInvalidConstraint(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $this->store($admin);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/new');

        $packages = "vendor/fine 1.0.0\nvendor/broken not-a-valid-constraint!@#";
        $form = $crawler->selectButton('Create')->form();
        $form['filter_list_entry_bulk[packages]'] = $packages;
        $form['filter_list_entry_bulk[list]'] = FilterLists::MALWARE->value;
        $crawler = $this->client->submit($form);

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('Line 2 (vendor/broken)', $crawler->html(), 'The failing line must be identified by its number.');
        static::assertStringContainsString('Invalid version constraint', $crawler->html());
        static::assertSame($packages, $crawler->selectButton('Create')->form()['filter_list_entry_bulk[packages]']->getValue(), 'The submitted lines must be preserved so they can be fixed.');

        $em = self::getEM();
        static::assertCount(0, $em->getRepository(FilterListEntry::class)->findAll(), 'A single bad line must prevent the whole batch, including the valid lines.');
    }

    public function testNewRejectsTheWholeBatchWhenOneLineHasAnInvalidPackageName(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $this->store($admin);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/new');

        $form = $crawler->selectButton('Create')->form();
        $form['filter_list_entry_bulk[packages]'] = "vendor/fine 1.0.0\nnovendorname 1.0.0";
        $form['filter_list_entry_bulk[list]'] = FilterLists::MALWARE->value;
        $crawler = $this->client->submit($form);

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('Line 2 (novendorname)', $crawler->html());
        static::assertStringContainsString('canonical', $crawler->html());

        $em = self::getEM();
        static::assertCount(0, $em->getRepository(FilterListEntry::class)->findAll());
    }

    public function testNewRejectsALineWithoutAVersionConstraint(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $this->store($admin);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/new');

        // The blank second line still counts, so the error must point at line 3.
        $form = $crawler->selectButton('Create')->form();
        $form['filter_list_entry_bulk[packages]'] = "vendor/fine 1.0.0\n\nvendor/needs-version";
        $form['filter_list_entry_bulk[list]'] = FilterLists::MALWARE->value;
        $crawler = $this->client->submit($form);

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('Line 3: a version constraint is required', $crawler->html(), 'Line numbers must match the textarea, blank lines included.');

        $em = self::getEM();
        static::assertCount(0, $em->getRepository(FilterListEntry::class)->findAll());
    }

    public function testNewRejectsTwoIdenticalLinesInTheSameBatch(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $this->store($admin);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/new');

        // Nothing is in the database yet, so only the in-batch check can catch this.
        $form = $crawler->selectButton('Create')->form();
        $form['filter_list_entry_bulk[packages]'] = "vendor/twice 1.0.0\nvendor/other 1.0.0\nvendor/twice 1.0.0";
        $form['filter_list_entry_bulk[list]'] = FilterLists::MALWARE->value;
        $crawler = $this->client->submit($form);

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('Line 3: duplicates line 1', $crawler->html());

        $em = self::getEM();
        static::assertCount(0, $em->getRepository(FilterListEntry::class)->findAll(), 'A duplicate within the batch must not reach the unique index.');
    }

    public function testNewRejectsTheWholeBatchWhenOneLineDuplicatesAnExistingEntry(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $existing = $this->createManualEntry(FilterLists::MALWARE, 'vendor/already-listed', '1.0.0', 'reason', null);
        $this->store($admin, $existing);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/new');

        $form = $crawler->selectButton('Create')->form();
        $form['filter_list_entry_bulk[packages]'] = "vendor/brand-new 1.0.0\nvendor/already-listed 1.0.0";
        $form['filter_list_entry_bulk[list]'] = FilterLists::MALWARE->value;
        $crawler = $this->client->submit($form);

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('already exists', $crawler->html());

        $em = self::getEM();
        static::assertNull($em->getRepository(FilterListEntry::class)->findOneBy(['packageName' => 'vendor/brand-new']), 'The valid line must not be created when another line collides with an existing entry.');
        static::assertCount(1, $em->getRepository(FilterListEntry::class)->findAll());
    }

    public function testEditManualEntryUpdatesAllPropertiesInPlace(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = $this->createManualEntry(FilterLists::MALWARE, 'vendor/manual-edit', '1.0.0', 'old reason', 'https://example.com/old');
        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/'.$entry->getPublicId().'/edit');
        static::assertResponseIsSuccessful();

        // Most fields locked for synced entries must be editable for manual ones,
        // but the package name is never editable once the entry exists.
        static::assertNotNull($crawler->filter('#filter_list_entry_packageName')->attr('disabled'), 'Package name must not be editable on edit, even for manual entries.');
        static::assertNull($crawler->filter('#filter_list_entry_reason')->attr('disabled'), 'Reason must be editable for manual entries.');

        $form = $crawler->selectButton('Save')->form();
        $form['filter_list_entry[version]'] = '>=2.0,<3.0';
        $form['filter_list_entry[reason]'] = 'new reason';
        $form['filter_list_entry[link]'] = 'https://example.com/new';
        $this->client->submit($form);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $em->clear();
        $refreshed = $em->getRepository(FilterListEntry::class)->findOneBy(['publicId' => $entry->getPublicId()]);
        static::assertNotNull($refreshed);
        static::assertSame('vendor/manual-edit', $refreshed->getPackageName());
        static::assertSame('>=2.0,<3.0', $refreshed->getVersion());
        static::assertSame('>=2.0,<3.0', $refreshed->getRemoteVersion(), 'Manual edits change the version directly, not via overwrite.');
        static::assertNull($refreshed->getOverwriteVersion());
        static::assertSame('new reason', $refreshed->getReason());
        static::assertSame('https://example.com/new', $refreshed->getLink());

        $audit = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditLogEventType::FilterListEntryEdited]);
        static::assertNotNull($audit);
        // The audit log must capture the prior value of every edited property.
        static::assertSame('1.0.0', $audit->attributes['previous']['version']);
        static::assertSame('old reason', $audit->attributes['previous']['reason']);
        static::assertSame('https://example.com/old', $audit->attributes['previous']['link']);
        static::assertSame(FilterLists::MALWARE->value, $audit->attributes['previous']['list']);
    }

    public function testEditManualEntryCannotRenamePackage(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = $this->createManualEntry(FilterLists::MALWARE, 'vendor/manual-locked-name', '1.0.0', 'reason', null);
        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/'.$entry->getPublicId().'/edit');
        static::assertResponseIsSuccessful();

        // The package name field is disabled, so it cannot be submitted at all.
        static::assertNotNull($crawler->filter('#filter_list_entry_packageName')->attr('disabled'), 'The package name must not be editable for manual entries.');

        // Editing other properties keeps the name and never spawns a replacement.
        $form = $crawler->selectButton('Save')->form();
        $form['filter_list_entry[version]'] = '2.0.0';
        $this->client->submit($form);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $em->clear();

        $refreshed = $em->getRepository(FilterListEntry::class)->findOneBy(['publicId' => $entry->getPublicId()]);
        static::assertNotNull($refreshed);
        static::assertSame('vendor/manual-locked-name', $refreshed->getPackageName());
        static::assertSame('2.0.0', $refreshed->getVersion());
        static::assertFalse($refreshed->isDisabled(), 'Editing a manual entry must not disable it.');
        static::assertCount(1, $em->getRepository(FilterListEntry::class)->findBy(['list' => FilterLists::MALWARE]), 'Editing must not create a second entry.');
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
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/bad-edit', '1.0.0'));
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
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/csrf', '1.0.0'));
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
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/csrf-enable', '1.0.0'));
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
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/already-disabled', '1.0.0'));
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
        $audits = $em->getRepository(AuditRecord::class)->findBy(['type' => AuditLogEventType::FilterListEntryDisabled]);
        static::assertCount(0, $audits);
    }

    public function testEnableAlreadyEnabledIsIdempotent(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/already-enabled', '1.0.0'));
        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/');
        $token = $crawler->filter('input[name="token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/filter-lists/'.$entry->getPublicId().'/enable', [
            'token' => $token,
        ]);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $audits = $em->getRepository(AuditRecord::class)->findBy(['type' => AuditLogEventType::FilterListEntryEnabled]);
        static::assertCount(0, $audits);
    }

    public function testDisableMarksPackageStaleForRedump(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $package = self::createPackage('vendor/stale', 'https://example.com/vendor/stale');
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/stale', '1.0.0'));
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
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/stale-edit', '1.0.0'));
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
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/stale-enable', '1.0.0'));
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

    public function testBulkDisableDisablesSelectedEntriesAndRecordsAudit(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $first = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/bulk-a', '1.0.0'));
        $second = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/bulk-b', '1.0.0'));
        $untouched = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/bulk-c', '1.0.0'));
        $this->store($admin, $first, $second, $untouched);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/');
        $token = $crawler->filter('input[name="token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/filter-lists/bulk', [
            'token' => $token,
            'action' => 'disable',
            'publicIds' => [$first->getPublicId(), $second->getPublicId()],
        ]);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $em->clear();
        $repo = $em->getRepository(FilterListEntry::class);
        static::assertTrue($repo->findOneBy(['publicId' => $first->getPublicId()])?->isDisabled());
        static::assertTrue($repo->findOneBy(['publicId' => $second->getPublicId()])?->isDisabled());
        static::assertFalse($repo->findOneBy(['publicId' => $untouched->getPublicId()])?->isDisabled(), 'Unselected entries must remain unchanged.');

        $audits = $em->getRepository(AuditRecord::class)->findBy(['type' => AuditLogEventType::FilterListEntryDisabled]);
        static::assertCount(2, $audits, 'One audit record must be written per disabled entry.');
    }

    public function testBulkEnableEnablesSelectedEntriesAndRecordsAudit(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $first = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/bulk-on-a', '1.0.0'));
        $second = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/bulk-on-b', '1.0.0'));
        $first->disable();
        $second->disable();
        $this->store($admin, $first, $second);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/?state=disabled');
        $token = $crawler->filter('input[name="token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/filter-lists/bulk', [
            'token' => $token,
            'action' => 'enable',
            'publicIds' => [$first->getPublicId(), $second->getPublicId()],
        ]);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $em->clear();
        $repo = $em->getRepository(FilterListEntry::class);
        static::assertFalse($repo->findOneBy(['publicId' => $first->getPublicId()])?->isDisabled());
        static::assertFalse($repo->findOneBy(['publicId' => $second->getPublicId()])?->isDisabled());

        $audits = $em->getRepository(AuditRecord::class)->findBy(['type' => AuditLogEventType::FilterListEntryEnabled]);
        static::assertCount(2, $audits);
    }

    public function testBulkDisableSkipsAlreadyDisabledEntries(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $active = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/bulk-mixed-active', '1.0.0'));
        $alreadyDisabled = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/bulk-mixed-disabled', '1.0.0'));
        $alreadyDisabled->disable();
        $this->store($admin, $active, $alreadyDisabled);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/');
        $token = $crawler->filter('input[name="token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/filter-lists/bulk', [
            'token' => $token,
            'action' => 'disable',
            'publicIds' => [$active->getPublicId(), $alreadyDisabled->getPublicId()],
        ]);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $audits = $em->getRepository(AuditRecord::class)->findBy(['type' => AuditLogEventType::FilterListEntryDisabled]);
        static::assertCount(1, $audits, 'Only the entry that actually changed state must be audited.');
    }

    public function testBulkMarksAffectedPackagesStaleForRedump(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $package = self::createPackage('vendor/bulk-stale', 'https://example.com/vendor/bulk-stale');
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/bulk-stale', '1.0.0'));
        $this->store($admin, $package, $entry);

        $em = self::getEM();
        $em->getConnection()->executeStatement('UPDATE package SET dumpedAtV2 = NOW() WHERE name = :name', ['name' => 'vendor/bulk-stale']);
        $em->clear();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/');
        $token = $crawler->filter('input[name="token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/filter-lists/bulk', [
            'token' => $token,
            'action' => 'disable',
            'publicIds' => [$entry->getPublicId()],
        ]);

        static::assertResponseRedirects('/admin/filter-lists/');

        $dumpedAt = $em->getConnection()->fetchOne('SELECT dumpedAtV2 FROM package WHERE name = :name', ['name' => 'vendor/bulk-stale']);
        static::assertNull($dumpedAt, 'Bulk changes must mark affected packages for re-dump.');
    }

    public function testBulkRequiresCsrfToken(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/bulk-csrf', '1.0.0'));
        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $this->client->request('POST', '/admin/filter-lists/bulk', [
            'token' => 'bogus',
            'action' => 'disable',
            'publicIds' => [$entry->getPublicId()],
        ]);

        static::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testBulkRejectsUnknownAction(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $this->store($admin);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/');
        $token = $crawler->filter('input[name="token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/filter-lists/bulk', [
            'token' => $token,
            'action' => 'destroy',
            'publicIds' => ['PKFE-AAAA-BBBB-CCCC'],
        ]);

        static::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testBulkWithoutSelectionDoesNothing(): void
    {
        $admin = self::createUser('filter-admin', 'admin@example.com', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $entry = FilterListEntry::fromRemote($this->createRemoteEntry('vendor/bulk-none', '1.0.0'));
        $this->store($admin, $entry);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/filter-lists/');
        $token = $crawler->filter('input[name="token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/filter-lists/bulk', [
            'token' => $token,
            'action' => 'disable',
        ]);

        static::assertResponseRedirects('/admin/filter-lists/');

        $em = self::getEM();
        $audits = $em->getRepository(AuditRecord::class)->findBy(['type' => AuditLogEventType::FilterListEntryDisabled]);
        static::assertCount(0, $audits);
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

    private function createManualEntry(FilterLists $list, string $packageName, string $version, ?string $reason, ?string $link): FilterListEntry
    {
        $request = new FilterListEntryRequest();
        $request->list = $list;
        $request->packageName = $packageName;
        $request->version = $version;
        $request->reason = $reason;
        $request->link = $link;

        return FilterListEntry::createManual($request);
    }
}
