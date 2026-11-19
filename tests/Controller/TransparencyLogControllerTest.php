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

use App\Command\ProjectTransparencyLogCommand;
use App\Entity\AuditRecord;
use App\Entity\PackageFreezeReason;
use App\Entity\PackageTransparencyLogRepository;
use App\Entity\User;
use App\Entity\WebAuthnCredential;
use App\Log\AuditLogEventType;
use App\Log\TransparencyLogEventType;
use App\QueryFilter\TransparencyLog\UserIdFilter;
use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

class TransparencyLogControllerTest extends IntegrationTestCase
{
    public function testAnonymousVisitorsAreSentToLogin(): void
    {
        $this->client->request('GET', '/transparency-log');

        static::assertResponseRedirects('/login/');
    }

    public function testShowsProjectedEvents(): void
    {
        $this->givenProjectedLog();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log');
        static::assertResponseIsSuccessful();

        $types = $crawler->filter('[data-test="log-type"]')->each(static fn ($element) => trim($element->text()));
        static::assertContains('Package created', $types);
    }

    public function testPageIsNotIndexable(): void
    {
        $this->givenProjectedLog();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('meta[name="robots"][content="noindex"]'));
    }

    public function testUnprojectedAuditRecordsAreNotShown(): void
    {
        $user = self::createUser('unprojected', 'unprojected@example.com');
        $organization = self::createOrganization('acme', 'ACME Corp');
        $this->store($user, $organization);

        // organization_created is out of scope for the projection, so it never reaches this page.
        $this->store(AuditRecord::organizationCreated($organization->id, $organization->slug, $organization->displayName, $user));

        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log');
        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('[data-test="log-type"]'));
    }

    public function testFiltersByPackageVendorAndType(): void
    {
        $this->givenProjectedLog();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['package' => 'vendor1/package1']));
        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('[data-test="log-type"]'));

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['vendor' => 'vendor1']));
        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('[data-test="log-type"]'));

        // A vendor with nothing projected returns an empty, non-crashing result.
        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['vendor' => 'nobody']));
        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('[data-test="log-type"]'));

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query([
            'type' => [TransparencyLogEventType::VersionCreated->value],
        ]));
        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('[data-test="log-type"]'));
    }

    public function testFreezeReasonIsRenderedAsALabelNotARawEnumValue(): void
    {
        $admin = self::createUser('freezer', 'freezer@example.org', roles: ['ROLE_ADMIN']);
        $this->store($admin);
        $package = self::createPackage('acme/frozen-log', 'https://github.com/acme/frozen-log');
        $this->store($package);

        $this->getEM()->getRepository(AuditRecord::class)->insert(
            AuditRecord::packageFrozen($package, $admin, PackageFreezeReason::RemoteIdMismatch),
        );

        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['package' => 'acme/frozen-log']));

        static::assertResponseIsSuccessful();
        $details = $crawler->filter('td.audit-log-details')->text();
        static::assertStringContainsString('Repository ID mismatch', $details);
        static::assertStringNotContainsString('remote_id', $details);
    }

    public function testSecondFactorEventsAreHiddenEvenWhenRequestedExplicitly(): void
    {
        $user = self::createUser('hidden', 'hidden@example.com');
        $this->store($user);
        $package = self::createPackage('vendor1/package1', 'https://github.com/vendor1/package1', maintainers: [$user]);
        $this->store($package);

        $this->getEM()->getRepository(AuditRecord::class)->insert(AuditRecord::totpAuthenticationDeactivated($user, $user, 'x'));
        $credential = new WebAuthnCredential($user, CredentialRecord::create(
            'credential-id', 'public-key', [], 'none', EmptyTrustPath::create(), Uuid::v4(), 'public-key', 'user-handle', 0,
        ));
        $this->getEM()->getRepository(AuditRecord::class)->insert(AuditRecord::webAuthnCredentialChanged($credential, AuditLogEventType::WebAuthnCredentialAdded));
        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query([
            'type' => [TransparencyLogEventType::TotpAuthenticationDeactivated->value, TransparencyLogEventType::WebAuthnCredentialAdded->value],
        ]));
        static::assertResponseIsSuccessful();

        // The hidden type is dropped from the filter, so this falls back to the unfiltered list, which
        // itself excludes it. The rows are still projected, see TransparencyLogProjectorTest.
        $types = $crawler->filter('[data-test="log-type"]')->each(static fn ($element) => trim($element->text()));
        static::assertNotContains('Authenticator app removed', $types);
        static::assertNotContains('Passkey or security key added', $types);
        static::assertCount(0, $crawler->filter('option[value="webauthn_credential_added"]'));
        static::assertContains('Package created', $types);
    }

    /**
     * The display templates are shared with the internal audit log, which does render the internal
     * moderation note, so make sure the public page keeps leaving it out.
     */
    public function testInternalModerationNoteNeverReachesThePublicLog(): void
    {
        $admin = self::createUser('deleter', 'deleter@example.org', roles: ['ROLE_ADMIN']);
        $this->store($admin);
        $package = self::createPackage('acme/deleted-log', 'https://github.com/acme/deleted-log');
        $this->store($package);

        $this->getEM()->getRepository(AuditRecord::class)->insert(
            AuditRecord::packageDeleted($package, $admin, 'spam', 'reporter jane, ticket #42'),
        );

        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['package' => 'acme/deleted-log']));

        static::assertResponseIsSuccessful();
        $details = $crawler->filter('td.audit-log-details')->text();
        static::assertStringContainsString('Reason: spam', $details);
        static::assertStringNotContainsString('Internal reason', $details);
        static::assertStringNotContainsString('ticket #42', $details);
    }

    /**
     * Deleting a package removes its row from the package table, so the entries are matched on the name
     * denormalised onto each of them. The entry announcing the deletion is the one a reader comes for,
     * and it would be unreachable if the filter resolved the name through the live package table.
     */
    public function testEntriesOfADeletedPackageStayFilterableByName(): void
    {
        $admin = self::createUser('purger', 'purger@example.org', roles: ['ROLE_ADMIN']);
        $this->store($admin);
        $package = self::createPackage('acme/gone-log', 'https://github.com/acme/gone-log');
        $this->store($package);

        $this->getEM()->getRepository(AuditRecord::class)->insert(
            AuditRecord::packageDeleted($package, $admin, 'spam', null),
        );
        $this->getEM()->getConnection()->executeStatement('DELETE FROM package WHERE id = :id', ['id' => $package->getId()]);

        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['package' => 'acme/gone-log']));

        static::assertResponseIsSuccessful();
        $types = $crawler->filter('[data-test="log-type"]')->each(static fn ($element) => trim($element->text()));
        // Newest first: the whole history of the gone package is still readable, deletion included.
        static::assertSame(['Package deleted', 'Package created'], $types);
    }

    /**
     * A late arrival: the unfreeze is the newest leaf but has the oldest event time.
     */
    public function testEntriesAreOrderedByEventTime(): void
    {
        $admin = self::createUser('late', 'late@example.org', roles: ['ROLE_ADMIN']);
        $this->store($admin);
        $package = self::createPackage('acme/late-log', 'https://github.com/acme/late-log');
        $this->store($package);

        $records = $this->getEM()->getRepository(AuditRecord::class);
        $records->insert(AuditRecord::packageFrozen($package, $admin, PackageFreezeReason::Spam));
        $records->insert(AuditRecord::packageUnfrozen($package, $admin));
        $this->runProjector();

        $conn = $this->getEM()->getConnection();
        foreach (['package_created' => '2026-01-01 09:00:00', 'package_frozen' => '2026-01-01 10:00:05', 'package_unfrozen' => '2026-01-01 10:00:01'] as $type => $datetime) {
            $conn->executeStatement('UPDATE package_transparency_log SET datetime = ? WHERE packageName = ? AND type = ?', [$datetime, 'acme/late-log', $type]);
        }

        $this->givenLoggedInVisitor();

        foreach ([['package' => 'acme/late-log'], ['package' => 'acme/late-log', 'datetime_from' => '2026-01-01T00:00:00']] as $query) {
            $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query($query));
            $types = $crawler->filter('[data-test="log-type"]')->each(static fn ($element) => trim($element->text()));
            static::assertSame(['Package frozen', 'Package unfrozen', 'Package created'], $types);
        }
    }

    /**
     * Many entries share the same event time, and the page breaks fall inside those groups.
     * If the cursor used only the time, entries at a page break would be skipped or shown twice.
     */
    public function testNextAndPreviousLinksWalkTheWholeLogInOrder(): void
    {
        $this->insertEntries('acme', 1, 45);
        $this->givenLoggedInVisitor();

        $expected = range(45, 1);
        usort($expected, static fn (int $a, int $b): int => [$b % 4, $b] <=> [$a % 4, $a]);
        $expected = array_map(static fn (int $n): string => 'acme/p'.$n, $expected);

        $crawler = $this->client->request('GET', '/transparency-log');
        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('a[rel="prev"]'));

        $pages = [$this->shownPackages($crawler)];
        while (\count($next = $crawler->filter('a[rel="next"]')) > 0) {
            $crawler = $this->client->click($next->link());
            static::assertResponseIsSuccessful();
            $pages[] = $this->shownPackages($crawler);
        }

        static::assertSame([20, 20, 5], array_map(\count(...), $pages));
        static::assertSame($expected, array_merge(...$pages));

        $crawler = $this->client->click($crawler->filter('a[rel="prev"]')->link());
        static::assertResponseIsSuccessful();
        static::assertSame($pages[1], $this->shownPackages($crawler));

        $crawler = $this->client->click($crawler->filter('a[rel="prev"]')->link());
        static::assertResponseIsSuccessful();
        static::assertSame($pages[0], $this->shownPackages($crawler));
        static::assertCount(0, $crawler->filter('a[rel="prev"]'));
    }

    public function testFiltersAreKeptWhenPaging(): void
    {
        $this->insertEntries('acme', 1, 25);
        $this->insertEntries('other', 26, 50);
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['vendor' => 'acme']));
        $shown = $this->shownPackages($crawler);

        $next = $crawler->filter('a[rel="next"]');
        static::assertStringContainsString('vendor=acme', (string) $next->attr('href'));
        $crawler = $this->client->click($next->link());
        static::assertResponseIsSuccessful();
        $shown = [...$shown, ...$this->shownPackages($crawler)];

        static::assertCount(25, $shown);
        foreach ($shown as $name) {
            static::assertStringStartsWith('acme/', $name);
        }
        static::assertCount(0, $crawler->filter('a[rel="next"]'));
    }

    public function testNewestLinkGoesBackToTheFirstPageAndKeepsFilters(): void
    {
        $this->insertEntries('acme', 1, 45);
        $this->insertEntries('other', 46, 50);
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['vendor' => 'acme']));
        $first = $this->shownPackages($crawler);
        static::assertCount(0, $crawler->filter('[data-test="newest-page"]'));

        $crawler = $this->client->click($crawler->filter('a[rel="next"]')->link());
        $crawler = $this->client->click($crawler->filter('a[rel="next"]')->link());

        $newest = $crawler->filter('[data-test="newest-page"]');
        static::assertSame('/transparency-log?vendor=acme', $newest->attr('href'));

        $crawler = $this->client->click($newest->link());
        static::assertResponseIsSuccessful();
        static::assertSame($first, $this->shownPackages($crawler));
    }

    public function testMalformedCursorIsABadRequest(): void
    {
        $this->givenLoggedInVisitor();

        $this->client->request('GET', '/transparency-log?cursor=garbage');
        static::assertResponseStatusCodeSame(400);
    }

    /**
     * Cursor values end up in the WHERE clause, so only cursors the app signed itself are accepted.
     */
    public function testUnsignedCursorIsABadRequest(): void
    {
        $this->givenLoggedInVisitor();

        $payload = json_encode(['f' => ['t.datetime' => '2026-01-01 10:00:00', 't.leafIndex' => 5], 'd' => 'n'], \JSON_THROW_ON_ERROR);
        $cursor = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        $this->client->request('GET', '/transparency-log?'.http_build_query(['cursor' => $cursor]));
        static::assertResponseStatusCodeSame(400);
    }

    public function testPageParameterFromOldLinksShowsTheFirstPage(): void
    {
        $this->insertEntries('acme', 1, 45);
        $this->givenLoggedInVisitor();

        $first = $this->shownPackages($this->client->request('GET', '/transparency-log'));
        $crawler = $this->client->request('GET', '/transparency-log?page=3');

        static::assertResponseIsSuccessful();
        static::assertSame($first, $this->shownPackages($crawler));
    }

    /**
     * Inserts package_created entries "<vendor>/p<leafIndex>" for leaf indexes $from to $to, with event
     * times repeating every four leaves.
     */
    private function insertEntries(string $vendor, int $from, int $to): void
    {
        $this->getEM()->getConnection()->executeStatement(<<<'SQL'
            INSERT INTO package_transparency_log (id, sourceAuditLogId, leafIndex, type, attributes, datetime, packageId, packageName, vendor)
            WITH RECURSIVE seq (n) AS (SELECT CAST(:from AS UNSIGNED) UNION ALL SELECT n + 1 FROM seq WHERE n < :to)
            SELECT UNHEX(MD5(CONCAT('id', n))), UNHEX(MD5(CONCAT('source', n))), n, 'package_created',
                JSON_OBJECT('name', CONCAT(:vendor, '/p', n), 'repository', 'https://github.com/acme/many', 'actor', 'automation'),
                DATE_ADD(:base, INTERVAL n % 4 SECOND), 1, CONCAT(:vendor, '/p', n), :vendor
            FROM seq
            SQL, ['from' => $from, 'to' => $to, 'vendor' => $vendor, 'base' => '2026-01-01 10:00:00']);
    }

    /**
     * @return list<string>
     */
    private function shownPackages(Crawler $crawler): array
    {
        return $crawler->filter('td.audit-log-details')->each(static function (Crawler $details): string {
            static::assertMatchesRegularExpression('{\b([a-z]+/p\d+)\b}', $details->text());
            preg_match('{\b([a-z]+/p\d+)\b}', $details->text(), $match);

            return $match[1];
        });
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function providePersonFilters(): iterable
    {
        yield 'user' => [['user' => 'pagedperson']];
        yield 'actor' => [['actor' => 'pagedperson']];
        yield 'user and actor' => [['user' => 'pagedperson', 'actor' => 'pagedperson']];
        yield 'user and time range' => [['user' => 'pagedperson', 'datetime_from' => '2000-01-01T00:00:00']];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('providePersonFilters')]
    public function testPersonFiltersPageThroughAllEntries(array $query): void
    {
        $this->givenAPersonWithMoreEntriesThanFitOnAPage();
        $this->givenLoggedInVisitor();

        $this->assertPagesShowEveryEntryOnce('/transparency-log?'.http_build_query($query));
    }

    public function testProfileLinkPagesThroughAllEntries(): void
    {
        $user = $this->givenAPersonWithMoreEntriesThanFitOnAPage();
        $this->givenLoggedInVisitor();

        $this->assertPagesShowEveryEntryOnce('/transparency-log?'.http_build_query(['user_id' => $user->getId()]));
    }

    /**
     * The person changes their email 25 times, and maintains one package, so each change is one entry.
     */
    private function givenAPersonWithMoreEntriesThanFitOnAPage(): User
    {
        $user = self::createUser('pagedperson', 'pagedperson@example.org');
        $this->store($user);
        $this->store(self::createPackage('acme/paged', 'https://github.com/acme/paged', maintainers: [$user]));
        for ($i = 1; $i <= 25; $i++) {
            $this->store(AuditRecord::emailChanged($user, $user, 'old'.$i.'@example.org'));
        }
        $this->runProjector();

        return $user;
    }

    private function assertPagesShowEveryEntryOnce(string $url): void
    {
        $crawler = $this->client->request('GET', $url);
        static::assertResponseIsSuccessful();

        $pages = 1;
        $emailChanges = $this->countEmailChanges($crawler);
        while (\count($next = $crawler->filter('a[rel="next"]')) > 0) {
            $crawler = $this->client->click($next->link());
            static::assertResponseIsSuccessful();
            $pages++;
            $emailChanges += $this->countEmailChanges($crawler);
        }

        static::assertSame(2, $pages);
        static::assertSame(25, $emailChanges);
    }

    private function countEmailChanges(Crawler $crawler): int
    {
        $types = $crawler->filter('[data-test="log-type"]')->each(fn ($element) => trim($element->text()));

        return \count(array_keys($types, 'Email address changed', true));
    }

    /**
     * Most entries name the person only as the actor, so the entry's userId is empty.
     */
    public function testActorFilterFindsEntriesThatOnlyNameThePersonAsTheActor(): void
    {
        $this->givenAPackageDeletedByAnAdmin();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['actor' => 'onlyactor']));
        static::assertResponseIsSuccessful();

        $types = $crawler->filter('[data-test="log-type"]')->each(fn ($element) => trim($element->text()));
        static::assertContains('Package deleted', $types);
    }

    /**
     * The User filter finds what an action was about, not what the person did.
     */
    public function testUserFilterDoesNotFindEntriesThePersonOnlyDid(): void
    {
        $this->givenAPackageDeletedByAnAdmin();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user' => 'onlyactor']));
        static::assertResponseIsSuccessful();

        $types = $crawler->filter('[data-test="log-type"]')->each(fn ($element) => trim($element->text()));
        static::assertNotContains('Package deleted', $types);
    }

    /**
     * A transfer has no subject. Its maintainers are found through the index only.
     */
    public function testOwnershipTransfersAreFoundByAMaintainerNamedOnlyInTheSnapshot(): void
    {
        $previous = self::createUser('snapshotonly', 'snapshotonly@example.org');
        $current = self::createUser('newowner', 'newowner@example.org');
        $admin = self::createUser('transfermod', 'transfermod@example.org', roles: ['ROLE_ADMIN']);
        $this->store($previous, $current, $admin);

        $package = self::createPackage('acme/transferred-log', 'https://github.com/acme/transferred-log', maintainers: [$current]);
        $this->store($package);
        $this->store(AuditRecord::packageTransferred($package, $admin, [$previous], [$current]));

        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user' => 'snapshotonly']));
        static::assertResponseIsSuccessful();

        $types = $crawler->filter('[data-test="log-type"]')->each(fn ($element) => trim($element->text()));
        static::assertSame(['Package transferred'], $types);
    }

    /**
     * A search by username finds every account that had that username.
     */
    public function testUserFilterReturnsTheHistoryOfTheNameAcrossEveryAccountThatHeldIt(): void
    {
        $this->givenARecycledUsername();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user' => 'handover']));
        static::assertResponseIsSuccessful();

        // the maintainer entries of both accounts, and the package created for the first one
        $types = $crawler->filter('[data-test="log-type"]')->each(fn ($element) => trim($element->text()));
        static::assertSame(['Maintainer added', 'Maintainer added', 'Package created'], $types);
    }

    /**
     * Each entry shows the account id, so the accounts can be told apart.
     */
    public function testEntriesRecordedUnderARecycledNameShowWhichAccountTheyAreAbout(): void
    {
        [$renamed, $successor] = $this->givenARecycledUsername();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user' => 'handover']));
        static::assertResponseIsSuccessful();

        // ->text() reads only the first row, so read all of them
        $details = implode(' ', $crawler->filter('td.audit-log-details')->each(fn ($node) => $node->text()));
        static::assertStringContainsString('(#'.$renamed->getId().')', $details);
        static::assertStringContainsString('(#'.$successor->getId().')', $details);
    }

    /**
     * The profile link searches by account, so it also finds entries from before a rename.
     */
    public function testProfileDeepLinkReturnsOneAccountsHistoryAcrossItsRename(): void
    {
        [$renamed, $successor] = $this->givenARecycledUsername();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user_id' => $renamed->getId()]));
        static::assertResponseIsSuccessful();

        // only the entries of this account, not of the account that has the username now
        static::assertCount(2, $crawler->filter('[data-test="log-type"]'));
        static::assertStringContainsString('(#'.$renamed->getId().')', $crawler->filter('td.audit-log-details')->text());

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user_id' => $successor->getId()]));
        static::assertCount(1, $crawler->filter('[data-test="log-type"]'));
        static::assertStringContainsString('(#'.$successor->getId().')', $crawler->filter('td.audit-log-details')->text());
    }

    /**
     * The account is both user and actor, so the index has two rows for the entry. The search must
     * return one. This is checked on the query, because Doctrine hides duplicates on the page, but they
     * would still make the count and the pages wrong.
     */
    public function testAccountSearchReturnsOneRowPerEntryWhenTheAccountIsBothUserAndActor(): void
    {
        $user = self::createUser('selfacting', 'selfacting@example.org');
        $this->store($user);
        $package = self::createPackage('acme/self-acting', 'https://github.com/acme/self-acting', maintainers: [$user]);
        $this->store($package);
        $this->store(AuditRecord::passwordChanged($user, $user));

        $this->runProjector();

        $qb = self::getService(PackageTransparencyLogRepository::class)->getQueryBuilderForPublicView();
        UserIdFilter::fromQuery(new InputBag(['user_id' => (string) $user->getId()]))->filter($qb);
        $ids = $qb->select('t.id')->getQuery()->getSingleColumnResult();

        static::assertNotEmpty($ids);
        static::assertSame(array_values(array_unique($ids)), $ids);
    }

    #[TestWith(['user'])]
    #[TestWith(['user_id'])]
    public function testDateRangeBoundsTheSearchIndexScan(string $searchBy): void
    {
        [$renamed, $successor] = $this->givenARecycledUsername();
        $search = $searchBy === 'user' ? ['user' => 'handover'] : ['user_id' => (string) $renamed->getId()];

        $conn = $this->getEM()->getConnection();
        $conn->executeStatement('UPDATE package_transparency_log_search SET datetime = ? WHERE userId = ?', ['2025-03-01 12:00:00', $renamed->getId()]);
        $conn->executeStatement('UPDATE package_transparency_log_search SET datetime = ? WHERE userId = ?', ['2026-03-01 12:00:00', $successor->getId()]);
        // keep the entries and the other index rows in sync with the copied datetime
        $conn->executeStatement('UPDATE package_transparency_log t JOIN package_transparency_log_search s ON s.leafIndex = t.leafIndex AND s.userId IN (?, ?) SET t.datetime = s.datetime', [$renamed->getId(), $successor->getId()]);
        $conn->executeStatement('UPDATE package_transparency_log_search s JOIN package_transparency_log t ON t.leafIndex = s.leafIndex SET s.datetime = t.datetime');

        $this->givenLoggedInVisitor();
        $this->client->enableProfiler();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query($search + ['datetime_from' => '2025-01-01T00:00:00', 'datetime_to' => '2025-12-31T23:59:59']));
        static::assertResponseIsSuccessful();
        static::assertCount(2, $crawler->filter('[data-test="log-type"]'));
        static::assertStringContainsString('(#'.$renamed->getId().')', $crawler->filter('td.audit-log-details')->text());

        $profile = $this->client->getProfile();
        static::assertNotFalse($profile);
        /** @var \Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector $collector */
        $collector = $profile->getCollector('db');
        $sqls = array_column($collector->getQueries()['default'], 'sql');
        $searchSqls = array_values(array_filter($sqls, static fn (string $sql) => str_starts_with($sql, 'SELECT') && str_contains($sql, ' package_transparency_log_search ')));
        static::assertNotEmpty($searchSqls);
        foreach ($searchSqls as $sql) {
            static::assertSame(1, preg_match('{(?:FROM|JOIN) package_transparency_log_search (\w+)}', $sql, $match));
            static::assertStringContainsString($match[1].'.datetime >= ?', $sql);
            static::assertStringContainsString($match[1].'.datetime <= ?', $sql);
        }

        // the other account's entry is outside the range
        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query($search + ['datetime_from' => '2027-01-01T00:00:00']));
        static::assertCount(0, $crawler->filter('[data-test="log-type"]'));
    }

    public function testDateRangeWithoutASearchOnlyBoundsTheLog(): void
    {
        $this->givenProjectedLog();
        $this->givenLoggedInVisitor();
        $this->client->enableProfiler();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['datetime_from' => '2000-01-01T00:00:00']));
        static::assertCount(1, $crawler->filter('[data-test="log-type"]'));

        $profile = $this->client->getProfile();
        static::assertNotFalse($profile);
        /** @var \Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector $collector */
        $collector = $profile->getCollector('db');
        $selects = array_filter(array_column($collector->getQueries()['default'], 'sql'), static fn (string $sql) => str_starts_with($sql, 'SELECT'));
        static::assertNotEmpty($selects);
        foreach ($selects as $sql) {
            static::assertStringNotContainsString('package_transparency_log_search', $sql);
        }
    }

    public function testAHandEditedAccountIdDegradesToAnUnfilteredList(): void
    {
        $this->givenProjectedLog();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user_id' => 'not-an-id']));

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('[data-test="log-type"]'));
    }

    /**
     * @return iterable<string, array{array<string, string|list<string>>}>
     */
    public static function provideViewsAcrossPackages(): iterable
    {
        yield 'unfiltered' => [[]];
        yield 'type' => [['type' => [TransparencyLogEventType::PasswordChanged->value]]];
        yield 'user' => [['user' => 'busymaintainer']];
        yield 'actor' => [['actor' => 'busymaintainer']];
        yield 'date' => [['datetime_from' => '2000-01-01T00:00:00']];
    }

    /**
     * @param array<string, string|list<string>> $query
     */
    #[DataProvider('provideViewsAcrossPackages')]
    public function testAccountEventShowsOnceInViewsAcrossPackages(array $query): void
    {
        $this->givenAnAccountEventOfAMaintainerOfThreePackages();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query($query));
        static::assertResponseIsSuccessful();

        static::assertSame(1, $this->countPasswordChanges($crawler));
        static::assertSame('2 more packages', $crawler->filter('[data-test="other-packages"]')->text());
    }

    public function testAccountEventShowsOnceInTheProfileLink(): void
    {
        $user = $this->givenAnAccountEventOfAMaintainerOfThreePackages();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user_id' => $user->getId()]));
        static::assertResponseIsSuccessful();

        static::assertSame(1, $this->countPasswordChanges($crawler));
        static::assertSame('2 more packages', $crawler->filter('[data-test="other-packages"]')->text());
    }

    public function testTwoAccountEventsOnOnePageEachShowOnceWithTheirOwnLink(): void
    {
        $this->givenAnAccountEventOfAMaintainerOfThreePackages();
        $other = self::createUser('pairmaintainer', 'pairmaintainer@example.org');
        $this->store($other);
        foreach (['acme/pair-one', 'acme/pair-two'] as $name) {
            $this->store(self::createPackage($name, 'https://github.com/'.$name, maintainers: [$other]));
        }
        $this->store(AuditRecord::passwordChanged($other, $other));
        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log');

        static::assertSame(2, $this->countPasswordChanges($crawler));
        $links = $crawler->filter('[data-test="other-packages"]');
        static::assertEqualsCanonicalizing(['1 more package', '2 more packages'], $links->each(fn ($link) => $link->text()));
        static::assertNotSame($links->eq(0)->attr('href'), $links->eq(1)->attr('href'));
    }

    /**
     * The event has more entries than fit on one page, so its hidden entries would fill the next page
     * if they were removed after loading the page instead of in the query.
     */
    public function testAnEventWithMorePackagesThanAPageShowsOnceWhenPaging(): void
    {
        $user = self::createUser('hugemaintainer', 'hugemaintainer@example.org');
        $this->store($user);
        for ($i = 1; $i <= 25; $i++) {
            $this->store(self::createPackage('acme/huge-'.$i, 'https://github.com/acme/huge-'.$i, maintainers: [$user]));
        }
        $this->store(AuditRecord::passwordChanged($user, $user));
        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $first = $this->client->request('GET', '/transparency-log');
        $passwordChanges = $this->countPasswordChanges($crawler);
        while (\count($next = $crawler->filter('a[rel="next"]')) > 0) {
            $crawler = $this->client->click($next->link());
            static::assertResponseIsSuccessful();
            $passwordChanges += $this->countPasswordChanges($crawler);
        }
        static::assertSame(1, $passwordChanges);
        static::assertSame('24 more packages', $first->filter('[data-test="other-packages"]')->text());

        $crawler = $this->client->click($first->filter('[data-test="other-packages"]')->link());
        $packages = [];
        while (true) {
            foreach ($crawler->filter('td.audit-log-details')->each(fn ($node) => $node->text()) as $details) {
                preg_match('{acme/huge-\d+}', $details, $match);
                $packages[] = $match[0];
            }
            if (\count($next = $crawler->filter('a[rel="next"]')) === 0) {
                break;
            }
            $crawler = $this->client->click($next->link());
            static::assertResponseIsSuccessful();
        }

        static::assertCount(25, $packages);
        static::assertCount(25, array_unique($packages));
    }

    public function testPackageViewShowsTheAccountEventOfEveryPackage(): void
    {
        $this->givenAnAccountEventOfAMaintainerOfThreePackages();
        $this->givenLoggedInVisitor();

        foreach (['acme/busy-one', 'acme/busy-two', 'other/busy-three'] as $package) {
            $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['package' => $package]));
            static::assertResponseIsSuccessful();

            static::assertSame(1, $this->countPasswordChanges($crawler), $package);
            static::assertStringContainsString($package, $crawler->filter('td.audit-log-details')->text());
            static::assertCount(0, $crawler->filter('[data-test="other-packages"]'));
        }

        // a person filter on a package view shows the entry of that package, also if other views hide it
        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['package' => 'other/busy-three', 'user' => 'busymaintainer']));
        static::assertSame(1, $this->countPasswordChanges($crawler));
    }

    public function testVendorViewShowsTheAccountEventOfEachPackageOfTheVendor(): void
    {
        $this->givenAnAccountEventOfAMaintainerOfThreePackages();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['vendor' => 'acme']));
        static::assertResponseIsSuccessful();
        static::assertSame(2, $this->countPasswordChanges($crawler));

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['vendor' => 'other']));
        static::assertSame(1, $this->countPasswordChanges($crawler));
    }

    public function testTheMorePackagesLinkListsEveryPackageOfTheEvent(): void
    {
        $this->givenAnAccountEventOfAMaintainerOfThreePackages();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log');
        static::assertSame('nofollow noindex', $crawler->filter('[data-test="other-packages"]')->attr('rel'));
        $crawler = $this->client->click($crawler->filter('[data-test="other-packages"]')->link());
        static::assertResponseIsSuccessful();

        $details = implode(' ', $crawler->filter('td.audit-log-details')->each(fn ($node) => $node->text()));
        static::assertSame(3, $this->countPasswordChanges($crawler));
        static::assertCount(3, $crawler->filter('[data-test="log-type"]'));
        foreach (['acme/busy-one', 'acme/busy-two', 'other/busy-three'] as $package) {
            static::assertStringContainsString($package, $details);
        }
        static::assertCount(1, $crawler->filter('input[type="hidden"][name="event"]'));
        static::assertStringContainsString('Showing every package of a single account event.', $crawler->filter('[data-test="event-filter-notice"]')->text());
    }

    public function testTypeFilterGroupsTheTypesWithPackageFirst(): void
    {
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log');
        static::assertResponseIsSuccessful();

        $groups = $crawler->filter('#type-filter optgroup');
        static::assertSame(['Package', 'Maintainers', 'Version', 'Security'], $groups->each(fn ($group) => $group->attr('label')));
        static::assertSame(TransparencyLogEventType::PackageCreated->value, $groups->eq(0)->filter('option')->attr('value'));
        static::assertSame(
            [TransparencyLogEventType::MaintainerAdded->value, TransparencyLogEventType::MaintainerRemoved->value, TransparencyLogEventType::PackageTransferred->value],
            $groups->eq(1)->filter('option')->each(fn ($option) => $option->attr('value')),
        );

        $security = $groups->eq(3)->filter('option')->each(fn ($option) => $option->attr('value'));
        static::assertContains(TransparencyLogEventType::PasswordChanged->value, $security);
        static::assertNotContains(TransparencyLogEventType::TotpAuthenticationActivated->value, $security);
        static::assertNotContains(TransparencyLogEventType::TotpAuthenticationDeactivated->value, $security);
    }

    /**
     * Like in the audit log, the date links to everything that happened around that time, so the
     * other filters are dropped.
     */
    public function testTheDateLinkShowsAllEntriesAroundThatTime(): void
    {
        $this->insertEntries('old', 1, 1);
        $this->givenAnAccountEventOfAMaintainerOfThreePackages();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['package' => 'other/busy-three']));
        $link = $crawler->filter('tr')->reduce(fn ($row) => str_contains($row->text(), 'Password changed'))->filter('td.audit-log-datetime a');
        static::assertCount(1, $link);
        parse_str((string) parse_url((string) $link->attr('href'), \PHP_URL_QUERY), $query);
        static::assertSame(['datetime_from', 'datetime_to'], array_keys($query));
        $from = new \DateTimeImmutable($query['datetime_from'], new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable($query['datetime_to'], new \DateTimeZone('UTC'));
        static::assertSame(240, $to->getTimestamp() - $from->getTimestamp());

        $crawler = $this->client->click($link->link());
        static::assertResponseIsSuccessful();

        static::assertSame(1, $this->countPasswordChanges($crawler));
        static::assertSame('2 more packages', $crawler->filter('[data-test="other-packages"]')->text());
        static::assertStringNotContainsString('old/p1', $crawler->filter('.audit-log-table')->text());
    }

    public function testAnInvalidEventIdDegradesToAnUnfilteredList(): void
    {
        $this->givenAnAccountEventOfAMaintainerOfThreePackages();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['event' => 'not-an-id']));

        static::assertResponseIsSuccessful();
        static::assertSame(1, $this->countPasswordChanges($crawler));
        static::assertCount(0, $crawler->filter('input[type="hidden"][name="event"]'));
    }

    public function testAnEventWithOnePackageHasNoMorePackagesLink(): void
    {
        $user = self::createUser('lonemaintainer', 'lonemaintainer@example.org');
        $this->store($user);
        $this->store(self::createPackage('acme/lone', 'https://github.com/acme/lone', maintainers: [$user]));
        $this->store(AuditRecord::passwordChanged($user, $user));
        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log');

        static::assertSame(1, $this->countPasswordChanges($crawler));
        static::assertCount(0, $crawler->filter('[data-test="other-packages"]'));
    }

    /**
     * The maintainer changes their password, which writes one entry for each of their three packages.
     */
    private function givenAnAccountEventOfAMaintainerOfThreePackages(): User
    {
        $user = self::createUser('busymaintainer', 'busymaintainer@example.org');
        $this->store($user);
        foreach (['acme/busy-one', 'acme/busy-two', 'other/busy-three'] as $name) {
            $this->store(self::createPackage($name, 'https://github.com/'.$name, maintainers: [$user]));
        }
        $this->store(AuditRecord::passwordChanged($user, $user));

        $this->runProjector();

        return $user;
    }

    private function countPasswordChanges(Crawler $crawler): int
    {
        $types = $crawler->filter('[data-test="log-type"]')->each(fn ($element) => trim($element->text()));

        return \count(array_keys($types, 'Password changed', true));
    }

    /**
     * Account A has the username `handover` and gets an entry. Then A is renamed, and account B takes
     * the username and gets an entry.
     *
     * @return array{\App\Entity\User, \App\Entity\User} the renamed account and the one that took its name
     */
    private function givenARecycledUsername(): array
    {
        $renamed = self::createUser('handover', 'handover-first@example.org');
        $admin = self::createUser('handoveradmin', 'handoveradmin@example.org', roles: ['ROLE_ADMIN']);
        $this->store($renamed, $admin);

        $package = self::createPackage('acme/handover', 'https://github.com/acme/handover', maintainers: [$renamed]);
        $this->store($package);
        $this->store(AuditRecord::maintainerAdded($package, $renamed, $admin));

        $renamed->setUsername('handover_old');
        $renamed->setUsernameCanonical('handover_old');
        $this->getEM()->flush();

        $successor = self::createUser('handover', 'handover-second@example.org');
        $this->store($successor);
        $this->store(AuditRecord::maintainerAdded($package, $successor, $admin));

        $this->runProjector();

        return [$renamed, $successor];
    }

    private function givenAPackageDeletedByAnAdmin(): void
    {
        $actor = self::createUser('onlyactor', 'onlyactor@example.org', roles: ['ROLE_ADMIN']);
        $this->store($actor);
        $package = self::createPackage('acme/actor-only', 'https://github.com/acme/actor-only');
        $this->store($package);
        $this->store(AuditRecord::packageDeleted($package, $actor));

        $this->runProjector();
    }

    private function givenProjectedLog(): void
    {
        $user = self::createUser('projected', 'projected@example.com');
        $this->store($user);
        $package = self::createPackage('vendor1/package1', 'https://github.com/vendor1/package1', maintainers: [$user]);
        $this->store($package);

        $this->runProjector();
    }

    /**
     * The log is only readable by logged in users, and the reader is deliberately not involved in any of
     * the events under test.
     */
    private function givenLoggedInVisitor(): void
    {
        $visitor = self::createUser('visitor', 'visitor@example.com');
        $this->store($visitor);

        $this->client->loginUser($visitor);
    }

    private function runProjector(): void
    {
        $tester = new CommandTester(self::getService(ProjectTransparencyLogCommand::class));
        $tester->execute(['--min-event-age-to-project' => '0']);
        $tester->assertCommandIsSuccessful();
    }
}
