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
use App\Audit\VersionDeletionReason;
use App\Entity\AuditRecord;
use App\Entity\Job;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\PackageReadme;
use App\Entity\User;
use App\Entity\Version;
use App\Service\Spam\FeatureExtractor;
use App\Service\Spam\SpamClassifier;
use App\Tests\IntegrationTestCase;
use Composer\Package\Version\VersionParser;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\NullLogger;

class PackageControllerTest extends IntegrationTestCase
{
    public function testView(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg');
        $this->store($package);

        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();
        self::assertSame('composer require test/pkg', $crawler->filter('.requireme input')->attr('value'));

        $auditLink = $crawler->filter('a[href*="transparency-log"]');
        self::assertCount(1, $auditLink);
        self::assertStringContainsString('package=test/pkg', (string) $auditLink->attr('href'));
        self::assertStringContainsString('noindex', (string) $auditLink->attr('rel'));
    }

    public function testFreezePackageAsModeratorAuditsAndSchedulesPurge(): void
    {
        $mod = self::createUser('mod', 'mod@example.org', roles: ['ROLE_DISABLE_PACKAGES']);
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $this->store($mod, $package);
        $packageId = $package->getId();

        $this->client->loginUser($mod);
        $crawler = $this->client->request('GET', '/packages/test/pkg');
        $form = $crawler->filter('#freeze-package-modal form')->form();
        $form['reason'] = 'spam';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(302);

        $em = self::getEM();
        $em->clear();
        $package = $em->find(Package::class, $packageId);
        self::assertSame(PackageFreezeReason::Spam, $package->getFreezeReason());

        // Freezing goes through the entity, so PackageListener records the transition.
        $record = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditRecordType::PackageFrozen->value, 'packageId' => $packageId]);
        self::assertNotNull($record, 'a PackageFrozen audit record should be created');

        // Spam suppresses the package, so a purge is scheduled.
        $job = $em->getRepository(Job::class)->findOneBy(['type' => 'package:purge']);
        self::assertNotNull($job, 'a package:purge job should be scheduled for a suppressing freeze');
        self::assertSame('test/pkg', $job->getPayload()['name']);
    }

    public function testFreezePackageAsGoneDoesNotPurge(): void
    {
        $mod = self::createUser('mod', 'mod@example.org', roles: ['ROLE_DISABLE_PACKAGES']);
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $this->store($mod, $package);
        $packageId = $package->getId();

        $this->client->loginUser($mod);
        $crawler = $this->client->request('GET', '/packages/test/pkg');
        $form = $crawler->filter('#freeze-package-modal form')->form();
        $form['reason'] = 'gone';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(302);

        $em = self::getEM();
        $em->clear();
        $package = $em->find(Package::class, $packageId);
        self::assertSame(PackageFreezeReason::Gone, $package->getFreezeReason());

        $record = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditRecordType::PackageFrozen->value, 'packageId' => $packageId]);
        self::assertNotNull($record, 'a PackageFrozen audit record should be created');
        self::assertSame('gone', $record->attributes['reason']);
        // a manual freeze is attributed to the moderator, unlike the crawler's 'automation'
        self::assertIsArray($record->attributes['actor']);
        self::assertSame('mod', $record->attributes['actor']['username']);

        // Gone is a gentle freeze: the package stops being crawled but its metadata keeps being served.
        self::assertNull($em->getRepository(Job::class)->findOneBy(['type' => 'package:purge']), 'no purge should be scheduled for a gentle freeze');
    }

    public function testFreezePackageDeniedWithoutRole(): void
    {
        $user = self::createUser('bob', 'bob@example.org');
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $this->store($user, $package);
        $packageId = $package->getId();

        $this->client->loginUser($user);
        $this->client->request('POST', '/package/test/pkg/freeze', ['reason' => 'spam', 'token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        self::getEM()->clear();
        self::assertFalse(self::getEM()->getRepository(Package::class)->find($packageId)->isFrozen());
    }

    public function testSpamListingShowsClassifierScoresWhenModelAvailable(): void
    {
        // Fixture weights: name token "widget" is strongly safe, "spam" strongly spammy.
        $antispam = self::createUser('mod', 'mod@example.org', roles: ['ROLE_DISABLE_PACKAGES']);
        $safe = self::createPackage('goodvendor/widget', 'https://example.org/goodvendor/widget');
        $safe->setSuspect('Too many views');
        $spam = self::createPackage('badvendor/spam', 'https://example.org/badvendor/spam');
        $spam->setSuspect('Too many views');
        $this->store($antispam, $safe, $spam);

        // A spammy README on the spam package exercises the second (readme) score column.
        $this->store(new PackageReadme($spam, '<p>Best deals <a href="https://casino.example/win">buy now</a></p>'));

        // Override the autowired classifier with one backed by the committed test fixture model.
        self::getContainer()->set(SpamClassifier::class, new SpamClassifier(
            new FeatureExtractor(),
            new NullLogger(),
            __DIR__.'/../Fixtures/spam-model.json',
        ));

        $this->client->loginUser($antispam);
        $crawler = $this->client->request('GET', '/admin/spam');
        self::assertResponseIsSuccessful();

        $listing = $crawler->filter('.packages')->text();
        self::assertStringContainsString('auto-safe', $listing, 'the metadata-safe package should be flagged auto-safe');
        self::assertStringContainsString('review', $listing, 'the spammy package should be flagged for review');
        self::assertStringContainsString('readme', $listing, 'the spam package has a README so its readme score should show');
        self::assertGreaterThanOrEqual(2, $crawler->filter('.packages .badge')->count());
    }

    public function testViewVendor(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg');
        $this->store($package);

        $crawler = $this->client->request('GET', '/packages/test/');
        self::assertResponseIsSuccessful();

        $auditLink = $crawler->filter('a[href*="transparency-log"]');
        self::assertCount(1, $auditLink);
        self::assertStringContainsString('vendor=test', (string) $auditLink->attr('href'));
        self::assertStringContainsString('noindex', (string) $auditLink->attr('rel'));
    }

    public function testEdit(): void
    {
        $user = self::createUser();
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$user]);

        $this->store($user, $package);

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();
        self::assertSame('example.com/test/pkg', $crawler->filter('.canonical')->text());

        $form = $crawler->selectButton('Edit')->form();
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Update')->form(['form[repository]' => 'https://github.com/composer/composer']);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSame('github.com/composer/composer', $crawler->filter('.canonical')->text());
    }

    public function testCreateMaintainer(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $newMaintainer = self::createUser('maintainer', 'maintainer@example.org');
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$owner]);

        $this->store($owner, $newMaintainer, $package);

        $this->client->loginUser($owner);

        $this->assertFalse($package->isMaintainer($newMaintainer));

        $crawler = $this->client->request('GET', '/packages/test/pkg');

        $form = $crawler->filter('[name="add_maintainer_form"]')->form();
        $form->setValues([
            'add_maintainer_form[user]' => 'maintainer',
        ]);

        $this->client->enableProfiler(); // This is required in 7.3.4 to assert emails were sent, see https://github.com/symfony/symfony/issues/61873
        $this->client->submit($form);

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertNotNull($email);
        $this->assertEmailHeaderSame($email, 'To', $newMaintainer->getEmail());

        $this->assertResponseRedirects('/packages/test/pkg');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $em = self::getEM();
        $em->clear();

        $maintainer = $em->getRepository(User::class)->find($newMaintainer->getId());
        $package = $em->getRepository(Package::class)->find($package->getId());

        $this->assertTrue($package->isMaintainer($maintainer));

        $auditRecord = $em->getRepository(\App\Entity\AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::MaintainerAdded->value,
            'packageId' => $package->getId(),
            'actorId' => $owner->getId(),
        ]);
        $this->assertNotNull($auditRecord);
    }

    public function testRemoveMaintainer(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $maintainer = self::createUser('maintainer', 'maintainer@example.org');
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$owner, $maintainer]);

        $this->store($owner, $maintainer, $package);

        $this->client->loginUser($owner);

        $this->assertTrue($package->isMaintainer($maintainer));

        $crawler = $this->client->request('GET', '/packages/test/pkg');

        $form = $crawler->filter('[name="remove_maintainer_form"]')->form();
        $form->setValues([
            'remove_maintainer_form[user]' => $maintainer->getId(),
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects('/packages/test/pkg');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $em = self::getEM();
        $em->clear();

        $maintainer = $em->getRepository(User::class)->find($maintainer->getId());
        $package = $em->getRepository(Package::class)->find($package->getId());

        $this->assertFalse($package->isMaintainer($maintainer));

        $auditRecord = $em->getRepository(\App\Entity\AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::MaintainerRemoved->value,
            'packageId' => $package->getId(),
            'actorId' => $owner->getId(),
        ]);

        $this->assertNotNull($auditRecord);
    }

    public function testTransferPackage(): void
    {
        $john = self::createUser('john', 'john@example.org');
        $alice = self::createUser('alice', 'alice@example.org');
        $bob = self::createUser('bob', 'bob@example.org');
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$john, $alice]);

        $this->store($john, $alice, $bob, $package);

        $this->client->loginUser($john);

        $this->assertTrue($package->isMaintainer($john));
        $this->assertTrue($package->isMaintainer($alice));
        $this->assertFalse($package->isMaintainer($bob));

        $crawler = $this->client->request('GET', '/packages/test/pkg');

        $form = $crawler->filter('[name="transfer_package_form"]')->form();
        $form->setValues([
            'transfer_package_form[maintainers][0]' => 'alice',
            'transfer_package_form[maintainers][1]' => 'bob',
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects('/packages/test/pkg');

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertNotNull($email);
        $this->assertEmailHeaderSame($email, 'To', $bob->getEmail());

        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $em = self::getEM();
        $em->clear();

        $package = $em->getRepository(Package::class)->find($package->getId());
        $this->assertNotNull($package);

        $maintainerIds = array_map(fn (User $user) => $user->getId(), $package->getMaintainers()->toArray());
        $this->assertContains($alice->getId(), $maintainerIds);
        $this->assertContains($bob->getId(), $maintainerIds);
        $this->assertNotContains($john->getId(), $maintainerIds);

        $auditRecord = $em->getRepository(\App\Entity\AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::PackageTransferred->value,
            'packageId' => $package->getId(),
        ]);

        $this->assertNotNull($auditRecord, 'Audit record not found');
    }

    #[TestWith(['does_not_exist', 'value is not a valid username'])]
    #[TestWith([null, 'at least one maintainer must be specified'])]
    public function testTransferPackageReturnsValidationError(?string $value, string $message): void
    {
        $alice = self::createUser('alice', 'alice@example.org');
        $bob = self::createUser('bob', 'bob@example.org', enabled: false);
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$alice]);

        $this->store($alice, $bob, $package);

        $this->client->loginUser($alice);

        $crawler = $this->client->request('GET', '/packages/test/pkg');

        $form = $crawler->filter('[name="transfer_package_form"]')->form();
        $form->setValues([
            'transfer_package_form[maintainers][0]' => $value,
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects('/packages/test/pkg');
        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $elements = $crawler->filter('.flash-container .alert-error');
        $this->assertCount(1, $elements);
        $text = $elements->text();
        $this->assertStringContainsStringIgnoringCase($message, $text);
    }

    #[TestWith([null, null, 200])]
    #[TestWith([null, 'auto_missing', 200])]
    #[TestWith([null, 'maintainer', 200])]
    #[TestWith([null, 'admin', 200])]
    #[TestWith([null, 'hidden', 404])]
    #[TestWith(['maintainer', 'hidden', 200])]
    #[TestWith(['admin', 'hidden', 200])]
    public function testViewPackageVersionRespectsHiddenVisibility(?string $actor, ?string $reason, int $expectedStatus): void
    {
        $maintainer = self::createUser('owner', 'owner@example.org');
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN']);
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$maintainer]);
        $version = $this->createStableVersion($package, '1.0.0');
        if ($reason !== null) {
            $version->setSoftDeletedAt(new \DateTimeImmutable());
            $version->setDeletionReason(VersionDeletionReason::from($reason));
        }
        $this->store($maintainer, $admin, $package, $version);

        match ($actor) {
            'maintainer' => $this->client->loginUser($maintainer),
            'admin' => $this->client->loginUser($admin),
            null => null,
        };

        $this->client->request('GET', '/versions/'.$version->getId().'.json');
        self::assertResponseStatusCodeSame($expectedStatus);

        if ($expectedStatus === 404) {
            $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertSame('error', $payload['status'] ?? null);
        }
    }

    public function testViewPackageVersionHiddenResponseIsNotSharedCached(): void
    {
        $maintainer = self::createUser('owner', 'owner@example.org');
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$maintainer]);

        $hidden = $this->createStableVersion($package, '1.0.0');
        $hidden->setSoftDeletedAt(new \DateTimeImmutable());
        $hidden->setDeletionReason(VersionDeletionReason::Hidden);

        $visibleVersion = $this->createStableVersion($package, '1.1.0');

        $this->store($maintainer, $package, $hidden, $visibleVersion);

        // Hidden, served to authorized maintainer -> must NOT be shared-cacheable.
        $this->client->loginUser($maintainer);
        $this->client->request('GET', '/versions/'.$hidden->getId().'.json');
        self::assertResponseStatusCodeSame(200);
        $cacheControl = $this->client->getResponse()->headers->get('Cache-Control', '');
        self::assertStringNotContainsString('s-maxage', $cacheControl, 'Hidden version JSON must not advertise a shared-cache TTL');

        // Non soft deleted version, served to anonymous -> keeps shared cache. Confirms the
        // exemption above is Hidden-specific, not a blanket disable.
        $this->client->restart();
        $this->client->request('GET', '/versions/'.$visibleVersion->getId().'.json');
        self::assertResponseStatusCodeSame(200);
        $cacheControl = $this->client->getResponse()->headers->get('Cache-Control', '');
        self::assertStringContainsString('s-maxage=86400', $cacheControl);
    }

    /**
     * Admins can hide a version that is already soft-deleted as gone-from-upstream or
     * maintainer-pulled; admin-pulled and already-hidden rows must be recovered first.
     */
    #[TestWith([null, true, 200])]
    #[TestWith([VersionDeletionReason::AutoDeletedMissing, true, 200])]
    #[TestWith([VersionDeletionReason::DeletedByMaintainer, true, 200])]
    #[TestWith([VersionDeletionReason::DeletedByAdmin, false, 403])]
    #[TestWith([VersionDeletionReason::Hidden, false, 403])]
    public function testAdminHideVersionAllowedTransitions(?VersionDeletionReason $reason, bool $buttonShown, int $expectedStatus): void
    {
        $removedAt = new \DateTimeImmutable('2024-01-02 03:04:05');

        $maintainer = self::createUser('owner', 'owner@example.org');
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN']);
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$maintainer]);

        $target = $this->createStableVersion($package, '1.0.0');
        if ($reason !== null) {
            $target->setSoftDeletedAt($removedAt);
            $target->setDeletionReason($reason);
        }
        // A never-deleted version always renders a hide form, giving us a valid CSRF token even in
        // the cases where the target row must not offer one.
        $live = $this->createStableVersion($package, '1.1.0');

        $this->store($maintainer, $admin, $package, $target, $live);
        $targetId = $target->getId();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();

        self::assertSame(
            $buttonShown ? 1 : 0,
            $crawler->filter('li.version[data-version-id="1.0.0"] .hide-version')->count(),
            'hide button visibility for reason '.($reason?->value ?? 'none')
        );

        $token = $crawler->filter('li.version[data-version-id="1.1.0"] .hide-version input[name="_token"]')->attr('value');
        $this->client->request('POST', '/versions/'.$targetId.'/admin-hide', ['_token' => $token, 'reason' => 'spam']);
        self::assertResponseStatusCodeSame($expectedStatus);

        $em = self::getEM();
        $em->clear();
        $reloaded = $em->getRepository(Version::class)->find($targetId);
        self::assertNotNull($reloaded);

        if ($expectedStatus !== 200) {
            self::assertSame($reason, $reloaded->getDeletionReason(), 'rejected request must not change the reason');

            return;
        }

        self::assertSame(VersionDeletionReason::Hidden, $reloaded->getDeletionReason());
        self::assertSame('spam', $reloaded->getDeletionReasonText());
        self::assertNotNull($reloaded->getSoftDeletedAt());

        if ($reason !== null) {
            self::assertGreaterThan(
                $removedAt,
                $reloaded->getSoftDeletedAt(),
                'hiding an already soft-deleted version restamps it with the time of the hide'
            );
        }
    }

    public function testAdminHideVersionDeniedForMaintainer(): void
    {
        $maintainer = self::createUser('owner', 'owner@example.org');
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$maintainer]);
        $version = $this->createStableVersion($package, '1.0.0');
        $version->setSoftDeletedAt(new \DateTimeImmutable());
        $version->setDeletionReason(VersionDeletionReason::AutoDeletedMissing);
        $this->store($maintainer, $package, $version);
        $versionId = $version->getId();

        $this->client->loginUser($maintainer);
        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.hide-version'), 'maintainers are never offered the hide action');

        $this->client->request('POST', '/versions/'.$versionId.'/admin-hide', ['_token' => 'x', 'reason' => 'spam']);
        self::assertResponseStatusCodeSame(403);

        self::getEM()->clear();
        self::assertSame(
            VersionDeletionReason::AutoDeletedMissing,
            self::getEM()->getRepository(Version::class)->find($versionId)->getDeletionReason()
        );
    }

    public function testUpdateHistoryDeniedWithoutUpdatePackagesRole(): void
    {
        $maintainer = self::createUser('maintainer', 'maintainer@example.org');
        $other = self::createUser('other', 'other@example.org', apiToken: 'api-token-2', safeApiToken: 'safe-api-token-2', githubId: '23456');
        $package = self::createPackage('test/pkg', 'https://example.org/pkg', maintainers: [$maintainer]);
        $this->store($maintainer, $other, $package);

        // anonymous is bounced to the login form
        $this->client->request('GET', '/packages/test/pkg/update-history');
        self::assertResponseRedirects('http://localhost/login/');

        // a maintainer of the package is not enough: the PackageActions::Update voter grants them the
        // View Log toast, but the full history is staff-only
        $this->client->loginUser($maintainer);
        $this->client->request('GET', '/packages/test/pkg/update-history');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($other);
        $this->client->request('GET', '/packages/test/pkg/update-history');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUpdateHistoryListsOnlyThisPackagesUpdateJobs(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_UPDATE_PACKAGES']);
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $otherPackage = self::createPackage('test/other', 'https://example.org/other');
        $this->store($admin, $package, $otherPackage);

        $older = $this->createUpdateJob($package, 'aaaa0000', '2026-08-01 10:00:00', ['status' => Job::STATUS_COMPLETED, 'message' => 'OLDER JOB MESSAGE']);
        $newer = $this->createUpdateJob($package, 'bbbb0000', '2026-08-02 10:00:00', ['status' => Job::STATUS_FAILED, 'message' => 'NEWER JOB MESSAGE']);
        $foreignPackageJob = $this->createUpdateJob($otherPackage, 'cccc0000', '2026-08-03 10:00:00', ['status' => Job::STATUS_COMPLETED, 'message' => 'OTHER PACKAGE MESSAGE']);

        // packageId is overloaded across job types - it holds a *user* id for githubuser:migrate - so a
        // job carrying this package's id under another type must not leak into the listing
        $foreignTypeJob = new Job('dddd0000', 'githubuser:migrate', ['id' => $package->getId(), 'old_scope' => 'a', 'new_scope' => 'b']);
        $foreignTypeJob->setPackageId($package->getId());
        $foreignTypeJob->setCreatedAt(new \DateTimeImmutable('2026-08-04 10:00:00'));
        $foreignTypeJob->complete(['status' => Job::STATUS_COMPLETED, 'message' => 'FOREIGN TYPE MESSAGE']);

        $this->store($older, $newer, $foreignPackageJob, $foreignTypeJob);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/packages/test/pkg/update-history');
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('tr[data-toggle="collapse"]');
        self::assertCount(2, $rows);
        // the whole summary row is the trigger, not just a cell in it
        self::assertSame('#update-job-bbbb0000', $rows->first()->attr('data-target'));
        self::assertCount(1, $crawler->filter('#update-job-bbbb0000'));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('OTHER PACKAGE MESSAGE', $html);
        self::assertStringNotContainsString('FOREIGN TYPE MESSAGE', $html);
        self::assertLessThan(
            strpos($html, 'OLDER JOB MESSAGE'),
            strpos($html, 'NEWER JOB MESSAGE'),
            'jobs should be listed newest first'
        );
    }

    public function testUpdateHistoryRendersLogAndEscapesResultJson(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_UPDATE_PACKAGES']);
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $this->store($admin, $package);

        $job = $this->createUpdateJob($package, 'aaaa0000', '2026-08-01 10:00:00', [
            'status' => Job::STATUS_ERRORED,
            'message' => 'Update of test/pkg failed',
            'details' => '<pre>ok <span style="color:green;">done</span></pre>',
            'exceptionMsg' => '<script>alert(1)</script>',
        ]);
        $this->store($job);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/packages/test/pkg/update-history');
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        // the sanitized log HTML is rendered as HTML, not escaped
        self::assertStringContainsString('<span style="color:green;">done</span>', $html);
        // ..and only once, i.e. details is excluded from the result JSON block rather than duplicated
        self::assertSame(1, substr_count($html, 'color:green'));

        // the payload block is pretty printed, and autoescaped (hence &quot; rather than ")
        self::assertStringContainsString('&quot;force_dump&quot;: false', $html);

        // the JSON blocks are autoescaped, so an exception message cannot inject markup
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testUpdateHistoryHandlesQueuedJobWithNoResult(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_UPDATE_PACKAGES']);
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $this->store($admin, $package);

        // no result at all, as for any job that has not completed yet
        $job = $this->createUpdateJob($package, 'aaaa0000', '2026-08-01 10:00:00');
        $this->store($job);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/packages/test/pkg/update-history');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('tr[data-toggle="collapse"]'));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('No log output recorded for this job.', $html);
        self::assertStringContainsString('No result recorded yet.', $html);
    }

    public function testUpdateHistoryEmptyState(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_UPDATE_PACKAGES']);
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $this->store($admin, $package);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/packages/test/pkg/update-history');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('.alert-info'));
        self::assertCount(0, $crawler->filter('tr[data-toggle="collapse"]'));
    }

    public function testPackagePageShowsUpdateHistoryLinkOnlyToStaff(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_UPDATE_PACKAGES']);
        $maintainer = self::createUser('maintainer', 'maintainer@example.org', apiToken: 'api-token-2', safeApiToken: 'safe-api-token-2', githubId: '23456');
        $package = self::createPackage('test/pkg', 'https://example.org/pkg', maintainers: [$maintainer]);
        $package->setUpdatedAt(new \DateTimeImmutable());
        $package->setCrawledAt(new \DateTimeImmutable());
        $version = $this->createStableVersion($package, '1.0.0');
        $this->store($admin, $maintainer, $package, $version);

        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/update-history"]'));

        $this->client->loginUser($maintainer);
        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/update-history"]'));

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[href$="/update-history"]')->count());
    }

    public function testPackageStatsPageStillRendersForStaff(): void
    {
        // stats.html.twig includes version_list.html.twig without package/showUpdated, so the new link
        // must stay behind the showUpdated guard
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_UPDATE_PACKAGES']);
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $package->setCrawledAt(new \DateTimeImmutable());
        $version = $this->createStableVersion($package, '1.0.0');
        $this->store($admin, $package, $version);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/packages/test/pkg/stats');
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed> $result
     */
    private function createUpdateJob(Package $package, string $id, string $createdAt, array $result = []): Job
    {
        $job = new Job($id, 'package:updates', [
            'id' => $package->getId(),
            'update_source_dist_url' => false,
            'delete_before' => false,
            'force_dump' => false,
            'source' => 'test',
        ]);
        $job->setPackageId($package->getId());
        $job->setCreatedAt(new \DateTimeImmutable($createdAt));
        if ($result !== []) {
            $job->complete($result);
        }

        return $job;
    }

    private function createStableVersion(Package $package, string $version): Version
    {
        $v = new Version();
        $v->setName($package->getName());
        $v->setVersion($version);
        $v->setNormalizedVersion(new VersionParser()->normalize($version));
        $v->setLicense(['MIT']);
        $v->setAutoload([]);
        $v->setDevelopment(false);
        $v->setPackage($package);
        $package->getVersions()->add($v);
        $v->setReleasedAt(new \DateTimeImmutable());
        $v->setUpdatedAt(new \DateTimeImmutable());

        return $v;
    }
}
