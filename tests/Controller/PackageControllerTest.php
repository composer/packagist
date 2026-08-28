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
use App\Entity\Download;
use App\Entity\Dependent;
use App\Entity\Job;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\PackageReadme;
use App\Entity\PackageRepository;
use App\Entity\User;
use App\Entity\Vendor;
use App\Entity\Version;
use App\Log\AuditLogEventType;
use App\Model\ProviderManager;
use App\Package\PackageListCache;
use App\Service\Spam\FeatureExtractor;
use App\Service\Spam\SpamClassifier;
use App\Tests\IntegrationTestCase;
use Composer\Package\Version\VersionParser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\NullLogger;
use Predis\Client;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DriverException;

class PackageControllerTest extends IntegrationTestCase
{
    private const int ER_QUERY_TIMEOUT = 3024;

    public function testDependentsPaginationIgnoresACachedCount(): void
    {
        $required = self::createPackage('test/required', 'https://example.com/test/required');
        $requirer = self::createPackage('test/requirer', 'https://example.com/test/requirer');
        $this->store($required, $requirer);
        $this->store(new Dependent($requirer, 'test/required', Dependent::TYPE_REQUIRE));

        // a stale count drove the pager, so `next` pointed at a page that does not exist and,
        // when it was low instead, the listing stopped short of the rows that were really there
        self::redisCache()->set('dep-count:test/required:all', '500');

        $this->client->request('GET', '/packages/test/required/dependents.json');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertCount(1, $data['packages']);
        self::assertArrayNotHasKey('next', $data);

        // the listing must not refresh the key it refused to read either
        self::assertSame('500', self::redisCache()->get('dep-count:test/required:all'));
    }

    public function testView(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg');
        $this->store($package);

        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();
        self::assertSame('composer require test/pkg', $crawler->filter('.requireme input')->attr('value'));

        // The package page deep-links into the transparency log filtered to this package, and does not
        // expose the admin audit log to non-auditors.
        $transparencyLink = $crawler->filter('a[href*="transparency-log"]');
        self::assertCount(1, $transparencyLink);
        self::assertSame('Transparency log', trim($transparencyLink->text()));
        self::assertStringContainsString('package=test/pkg', (string) $transparencyLink->attr('href'));
        self::assertStringContainsString('noindex', (string) $transparencyLink->attr('rel'));
        self::assertCount(0, $crawler->filter('a[href*="/admin/audit-log"]'));
    }

    public function testVersionListMakesTheWholeVersionNumberCellALink(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg');
        $version = new Version();
        $version->setPackage($package);
        $version->setName($package->getName());
        $version->setVersion('1.0.0');
        $version->setNormalizedVersion('1.0.0.0');
        $version->setDevelopment(false);
        $version->setLicense(['MIT']);
        $version->setAutoload([]);
        $package->getVersions()->add($version);
        $this->store($package, $version);

        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();

        // .version-number is the flex item spanning the row, so it has to be the anchor itself:
        // wrapped in a div, only the version text links and clicking the rest of the cell leaves
        // the URL hash on the previously opened version. css/app.scss and js/view.js both resolve
        // .version-number to this element.
        $link = $crawler->filter('.versions .version a.version-number');
        self::assertCount(1, $link);
        self::assertSame('#1.0.0', $link->attr('href'));
        self::assertCount(0, $crawler->filter('.versions .version div.version-number'));
    }

    public function testPackagePageOmitsManagementFormsForVisitorsWhoCannotUseThem(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        // a second maintainer is required for remove_maintainer to be granted at all
        $comaintainer = self::createUser('comaintainer', 'comaintainer@example.org');
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$owner, $comaintainer]);
        $this->store($owner, $comaintainer, $package);

        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();

        // The controller skips building these entirely when the visitor lacks the grant, which also
        // skips the EntityType choice-list query behind the remove-maintainer form.
        self::assertCount(0, $crawler->filter('[name="add_maintainer_form"]'));
        self::assertCount(0, $crawler->filter('[name="remove_maintainer_form"]'));
        self::assertCount(0, $crawler->filter('[name="transfer_package_form"]'));
        self::assertCount(0, $crawler->filter('form.delete'));

        // ...and still builds them for someone who can, so the skip is keyed on the grant only.
        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('[name="add_maintainer_form"]'));
        self::assertCount(1, $crawler->filter('[name="remove_maintainer_form"]'));
        self::assertCount(1, $crawler->filter('[name="transfer_package_form"]'));
        self::assertCount(1, $crawler->filter('form.delete'));
    }

    public function testNeverCrawledPackageOnlyAutoUpdatesForVisitorsWhoCanUpdateIt(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        // ROLE_EDIT_PACKAGES and ROLE_UPDATE_PACKAGES are siblings in the role hierarchy, so an
        // edit-only moderator sees the Manage menu without the force-update form in it
        $editor = self::createUser('editor', 'editor@example.org', githubId: '23456', roles: ['ROLE_EDIT_PACKAGES']);
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg', maintainers: [$owner]);
        self::assertNull($package->getCrawledAt());
        $this->store($owner, $editor, $package);

        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.package[data-force-crawl]'));

        // js/view.js submits .force-update on seeing data-force-crawl, so the two have to be granted
        // together - on its own the attribute makes the page fire a request with no action URL
        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.package[data-force-crawl]'));
        self::assertCount(1, $crawler->filter('.package form.force-update'));

        $this->client->loginUser($editor);
        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.package form.force-update'));
        self::assertCount(0, $crawler->filter('.package[data-force-crawl]'));
    }

    public function testPackagePageOnlyCountsViewsWhileTheSpamHeuristicCanUseThem(): void
    {
        $fresh = self::createPackage('test/fresh', 'https://example.com/test/fresh');
        $established = self::createPackage('test/established', 'https://example.com/test/established');
        $this->store($fresh, $established);

        $redis = $this->redis();
        $redis->del(['views:'.$fresh->getId(), 'views:'.$established->getId()]);
        // getDownloads() reads the package total straight off this key
        $redis->set('dl:'.$established->getId(), '5000');

        $this->client->request('GET', '/packages/test/fresh');
        self::assertResponseIsSuccessful();
        self::assertSame('1', $redis->get('views:'.$fresh->getId()));

        $this->client->request('GET', '/packages/test/established');
        self::assertResponseIsSuccessful();
        self::assertNull(
            $redis->get('views:'.$established->getId()),
            'a package past the download threshold can never trip the heuristic, so it must not pay for the counter',
        );

        $redis->del(['views:'.$fresh->getId(), 'dl:'.$established->getId()]);
    }

    public function testPackagePageDropsTheViewCounterOnceTheHeuristicHasFired(): void
    {
        $package = self::createPackage('test/spammy', 'https://example.com/test/spammy');
        $this->store($package);

        $redis = $this->redis();
        $redis->set('views:'.$package->getId(), '99');

        $this->client->request('GET', '/packages/test/spammy');
        self::assertResponseIsSuccessful();

        $em = self::getEM();
        $em->clear();
        $reloaded = $em->find(Package::class, $package->getId());
        self::assertNotNull($reloaded);
        self::assertSame('Too many views', $reloaded->getSuspect());
        self::assertNull(
            $redis->get('views:'.$package->getId()),
            'the counter has done its job, keeping it would only grow a key nothing reads',
        );
    }

    public function testPackagePageDropsTheViewCounterOfAVerifiedVendor(): void
    {
        $vendor = new Vendor('verifiedvendor');
        $vendor->setVerified(true);
        $package = self::createPackage('verifiedvendor/pkg', 'https://example.com/verifiedvendor/pkg');
        $this->store($vendor, $package);

        $redis = $this->redis();
        $redis->set('views:'.$package->getId(), '99');

        $this->client->request('GET', '/packages/verifiedvendor/pkg');
        self::assertResponseIsSuccessful();

        $em = self::getEM();
        $em->clear();
        $reloaded = $em->find(Package::class, $package->getId());
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isSuspect(), 'a verified vendor is never flagged');
        self::assertNull(
            $redis->get('views:'.$package->getId()),
            'nothing can ever come of this counter, and resetting it stops the isVerified() lookup running on every view',
        );
    }

    private function redis(): Client
    {
        $client = static::getContainer()->get('snc_redis.default');
        self::assertInstanceOf(Client::class, $client);

        return $client;
    }

    #[TestWith(['/packages/test/pkg/dependents', 'text/html'])]
    #[TestWith(['/packages/test/pkg/dependents.json', 'application/json'])]
    #[TestWith(['/packages/test/pkg/suggesters', 'text/html'])]
    #[TestWith(['/packages/test/pkg/suggesters.json', 'application/json'])]
    public function testListingsDegradeWhenTheStatementTimeoutFires(string $url, string $expectedType): void
    {
        $this->stubListingsWith(new DriverException(self::driverException(self::ER_QUERY_TIMEOUT), null));

        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(503);
        self::assertStringStartsWith($expectedType, (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('300', $this->client->getResponse()->headers->get('Retry-After'), 'crawlers must be told to back off');
    }

    #[TestWith(['/packages/test/pkg/dependents'])]
    #[TestWith(['/packages/test/pkg/suggesters'])]
    public function testListingsDoNotSwallowOtherDatabaseErrors(string $url): void
    {
        // Every DBAL failure extends DriverException, so only the timeout may degrade to a 503 -
        // a lost connection or a syntax error has to surface instead of being reported as a listing
        // that is merely too large to sort.
        $this->stubListingsWith(new ConnectionLost(self::driverException(2006), null));

        $this->client->catchExceptions(true);
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(500);
    }

    public function testJsonSuggestersRefuseToPageBeyondTheCap(): void
    {
        // it sorts the whole set to build each page, so the cost grows with the offset. The
        // dependents listing is deliberately not capped alongside it - see the test below.
        $this->client->request('GET', '/packages/test/pkg/suggesters.json', ['page' => '999999']);

        self::assertResponseStatusCodeSame(400);
        self::assertStringStartsWith('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testJsonDependentsLinksKeepTheRequiresFilter(): void
    {
        // A consumer walking next must stay on the filter it asked for, otherwise it gets rows of
        // every type back and the traversal never matches what it asked for.
        $repo = $this->createStub(PackageRepository::class);
        $repo->method('getDependentsUnsorted')->willReturn(['packages' => [
            ['id' => 1, 'name' => 'test/dep', 'description' => null, 'type' => 'library', 'language' => null, 'abandoned' => 0, 'replacementPackage' => null],
        ], 'cursor' => 42]);
        $repo->method('getDefaultBranchRequireFor')->willReturn([]);
        static::getContainer()->set(PackageRepository::class, $repo);

        $this->client->request('GET', '/packages/test/pkg/dependents.json', ['requires' => 'require']);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertStringContainsString('requires=require', $data['next'] ?? '');
        self::assertStringContainsString('after=42', $data['next'] ?? '', 'json walks by cursor, not by page');
        self::assertStringContainsString('requires=require', $data['unordered'] ?? '');
        self::assertStringContainsString('requires=require', $data['ordered_by_downloads'] ?? '');
    }

    public function testDependentsPageDegradesToAnUnsortedListing(): void
    {
        $this->stubDependentsTimingOutWhenSorted(fallbackWorks: true);

        $this->client->request('GET', '/packages/test/pkg/dependents', ['order_by' => 'downloads']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('no particular order', $body, 'the reader has to be told the listing is not ordered');
        self::assertStringContainsString('test/dep', $body, 'the rows themselves are still shown');
        self::assertStringContainsString('<span class="active">downloads</span>', $body, 'the tab still reflects what was asked for - the url and the pager links say the same');
        self::assertStringContainsString('order_by=none', $body, 'and the cheap listing stays a link, so the reader is not left on the sort that just failed');
    }

    public function testDependentsJsonStillFailsWhenTheSortTimesOut(): void
    {
        // Deliberate asymmetry: a json client cannot see the warning, so it gets the error instead
        // of silently receiving arbitrarily ordered rows.
        $this->stubDependentsTimingOutWhenSorted(fallbackWorks: true);

        $this->client->request('GET', '/packages/test/pkg/dependents.json', ['order_by' => 'downloads']);

        self::assertResponseStatusCodeSame(503);
    }

    public function testDependentsPageFailsWhenEvenTheUnsortedQueryTimesOut(): void
    {
        $this->stubDependentsTimingOutWhenSorted(fallbackWorks: false);

        $this->client->request('GET', '/packages/test/pkg/dependents');

        self::assertResponseStatusCodeSame(503);
    }

    public function testDependentsPageDropsTheRequirementWhenItsLookupTimesOut(): void
    {
        // this query only annotates rows already in hand, so losing it costs the "Latest version
        // requires" line and nothing else - a 503 for the whole listing would be the worse trade
        $repo = $this->createStub(PackageRepository::class);
        $repo->method('getDependentCount')->willReturn(1);
        $repo->method('getDependents')->willReturn([
            ['id' => 1, 'name' => 'test/dep', 'description' => null, 'type' => 'library', 'language' => null, 'abandoned' => 0, 'replacementPackage' => null],
        ]);
        $repo->method('getDefaultBranchRequireFor')->willThrowException(new DriverException(self::driverException(self::ER_QUERY_TIMEOUT), null));
        static::getContainer()->set(PackageRepository::class, $repo);

        $this->client->request('GET', '/packages/test/pkg/dependents');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('test/dep', $body, 'the rows themselves are still shown');
        self::assertStringNotContainsString('Latest version requires', $body);
    }

    public function testDependentsRejectsTheRemovedNameOrderInJson(): void
    {
        $this->client->request('GET', '/packages/test/pkg/dependents.json', ['order_by' => 'name']);

        self::assertResponseStatusCodeSame(400);
        // a consumer that was paging through it needs to be told where to go, not just refused
        self::assertStringContainsString('order_by=none', (string) $this->client->getResponse()->getContent());
    }

    public function testDependentsRedirectsTheRemovedNameOrderInHtml(): void
    {
        // it was the html default, so bookmarks and inbound links carry it; they land on the
        // current default instead of an unstyled 400, keeping the filter they came with
        $this->client->request('GET', '/packages/test/pkg/dependents', ['order_by' => 'name', 'requires' => 'require-dev']);

        self::assertResponseRedirects('/packages/test/pkg/dependents?requires=require-dev');
    }

    public function testDependentsCapsThePagesOfASortedListing(): void
    {
        $this->client->request('GET', '/packages/test/pkg/dependents.json', ['order_by' => 'downloads', 'page' => 6]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('order_by=none', (string) $this->client->getResponse()->getContent());
    }

    public function testDependentsPagesTheUnsortedHtmlListingAsDeepAsAsked(): void
    {
        // no sort means no cost that grows with the page, so the sorted listing's cap has nothing
        // to buy here. Page 600 is past where the json cap used to stop, and short of where
        // illuminate/support ends.
        $this->loginPastThePageWall();

        $this->client->request('GET', '/packages/test/pkg/dependents', ['order_by' => 'none', 'page' => 600]);

        self::assertResponseIsSuccessful();
    }

    public function testDependentsSurvivesAPageNumberThatWouldOverflowTheOffset(): void
    {
        // uncapped paging means nothing rejects an absurd page before (page - 1) * perPage runs,
        // and that arithmetic overflowing to a float is a TypeError under strict_types
        $this->loginPastThePageWall();

        $this->client->request('GET', '/packages/test/pkg/dependents', ['order_by' => 'none', 'page' => (string) \PHP_INT_MAX]);

        self::assertResponseIsSuccessful();
    }

    public function testJsonDependentsDefaultsToTheUnsortedListing(): void
    {
        $this->stubUnsortedDependents(cursor: 42);

        $this->client->request('GET', '/packages/test/pkg/dependents.json');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertStringContainsString('after=42', $data['next'] ?? '');
        self::assertStringContainsString('order_by=none', $data['next'] ?? '');
    }

    public function testJsonDependentsStopsOfferingNextAtTheEndOfTheSet(): void
    {
        $this->stubUnsortedDependents(cursor: null);

        $this->client->request('GET', '/packages/test/pkg/dependents.json');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('next', $data);
    }

    public function testHtmlDependentsDefaultsToTheDownloadOrder(): void
    {
        // an anonymous visitor only gets three pages, so 45 arbitrary packages would be no use;
        // the sort is affordable here because html traffic is spread across small packages
        $repo = $this->createStub(PackageRepository::class);
        $repo->method('getDependentCount')->willReturn(1);
        $repo->method('getDependents')->willReturn([
            ['id' => 1, 'name' => 'test/dep', 'description' => null, 'type' => 'library', 'language' => null, 'abandoned' => 0, 'replacementPackage' => null],
        ]);
        $repo->method('getDefaultBranchRequireFor')->willReturn([]);
        static::getContainer()->set(PackageRepository::class, $repo);

        $this->client->request('GET', '/packages/test/pkg/dependents');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('test/dep', $body);
        self::assertStringContainsString('<span class="active">downloads</span>', $body);
        self::assertStringNotContainsString('listing.unsorted_warning', $body);
    }

    public function testJsonDependentsRefusesAPageNumber(): void
    {
        // json iterates this listing by following next. Answering a self-constructed page number
        // with the first page and a 200 is the one outcome that corrupts a consumer silently.
        $this->stubUnsortedDependents(cursor: 42);

        $this->client->request('GET', '/packages/test/pkg/dependents.json', ['page' => 2]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('next', (string) $this->client->getResponse()->getContent());
    }

    public function testJsonDependentsStopsOfferingNextAtTheSortedCap(): void
    {
        // the docs say to follow next until it is gone, so it must never point at a page the
        // listing itself refuses
        $repo = $this->createStub(PackageRepository::class);
        $repo->method('getDependentCount')->willReturn(100000);
        $repo->method('getDefaultBranchRequireFor')->willReturn([]);
        $repo->method('getDependents')->willReturn([
            ['id' => 1, 'name' => 'test/dep', 'description' => null, 'type' => 'library', 'language' => null, 'abandoned' => 0, 'replacementPackage' => null],
        ]);
        static::getContainer()->set(PackageRepository::class, $repo);

        $this->client->request('GET', '/packages/test/pkg/dependents.json', ['order_by' => 'downloads', 'page' => 4]);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('next', $data, 'still short of the cap');

        $this->client->request('GET', '/packages/test/pkg/dependents.json', ['order_by' => 'downloads', 'page' => 5]);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('next', $data, 'page 6 would be a 400');
    }

    public function testDependentsPagerStopsAtTheSortedCap(): void
    {
        // a pager built from the real count would offer thousands of numbered links, every one of
        // them past page 5 a bare 400
        $repo = $this->createStub(PackageRepository::class);
        $repo->method('getDependentCount')->willReturn(100000);
        $repo->method('getDefaultBranchRequireFor')->willReturn([]);
        $repo->method('getDependents')->willReturn([
            ['id' => 1, 'name' => 'test/dep', 'description' => null, 'type' => 'library', 'language' => null, 'abandoned' => 0, 'replacementPackage' => null],
        ]);
        static::getContainer()->set(PackageRepository::class, $repo);

        $this->client->request('GET', '/packages/test/pkg/dependents');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('100000', $body, 'the header still shows the real total');
        self::assertStringContainsString('page=5', $body, 'the pager is rendering links at all');
        self::assertStringNotContainsString('page=6', $body);
    }

    /**
     * Anonymous visitors are refused past page 3, so the deep pages need someone logged in. No
     * repository stub here: these run against the real query, which simply comes back empty.
     */
    private function loginPastThePageWall(): void
    {
        $user = self::createUser();
        $this->store($user);
        $this->client->loginUser($user);
    }

    private function stubUnsortedDependents(?int $cursor): void
    {
        $repo = $this->createStub(PackageRepository::class);
        $repo->method('getDependentCount')->willReturn(1);
        $repo->method('getDependentsUnsorted')->willReturn(['packages' => [
            ['id' => 1, 'name' => 'test/dep', 'description' => null, 'type' => 'library', 'language' => null, 'abandoned' => 0, 'replacementPackage' => null],
        ], 'cursor' => $cursor]);
        $repo->method('getDefaultBranchRequireFor')->willReturn([]);
        static::getContainer()->set(PackageRepository::class, $repo);
    }

    private function stubDependentsTimingOutWhenSorted(bool $fallbackWorks): void
    {
        $repo = $this->createStub(PackageRepository::class);
        $repo->method('getDependentCount')->willReturn(1);
        $repo->method('getDefaultBranchRequireFor')->willReturn([]);
        $repo->method('getDependents')->willThrowException(new DriverException(self::driverException(self::ER_QUERY_TIMEOUT), null));
        $repo->method('getDependentsUnsorted')->willReturnCallback(
            function () use ($fallbackWorks): array {
                if (!$fallbackWorks) {
                    throw new DriverException(self::driverException(self::ER_QUERY_TIMEOUT), null);
                }

                return ['packages' => [
                    ['id' => 1, 'name' => 'test/dep', 'description' => null, 'type' => 'library', 'language' => null, 'abandoned' => 0, 'replacementPackage' => null],
                ], 'cursor' => null];
            },
        );
        static::getContainer()->set(PackageRepository::class, $repo);
    }

    /**
     * Replaces the repository before any DB work, because the TestContainer refuses to swap a
     * private service that has already been instantiated.
     */
    private function stubListingsWith(DriverException $e): void
    {
        $repo = $this->createStub(PackageRepository::class);
        $repo->method('getDependents')->willThrowException($e);
        $repo->method('getDependentsUnsorted')->willThrowException($e);
        $repo->method('getSuggests')->willThrowException($e);
        static::getContainer()->set(PackageRepository::class, $repo);
    }

    /**
     * DBAL's PDO exception class is internal and final, so build the driver-level exception off the
     * public Driver\Exception interface instead.
     */
    private static function driverException(int $code): DriverExceptionInterface
    {
        return new class($code) extends \Exception implements DriverExceptionInterface {
            public function __construct(int $code)
            {
                parent::__construct('Query execution was interrupted', $code);
            }

            public function getSQLState(): ?string
            {
                return 'HY000';
            }
        };
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
        $record = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditLogEventType::PackageFrozen->value, 'packageId' => $packageId]);
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

        $record = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditLogEventType::PackageFrozen->value, 'packageId' => $packageId]);
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
            'type' => AuditLogEventType::MaintainerAdded->value,
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
            'type' => AuditLogEventType::MaintainerRemoved->value,
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
            'type' => AuditLogEventType::PackageTransferred->value,
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

        $rows = $crawler->filter('tr[data-bs-toggle="collapse"]');
        self::assertCount(2, $rows);
        // the whole summary row is the trigger, not just a cell in it
        self::assertSame('#update-job-bbbb0000', $rows->first()->attr('data-bs-target'));
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

        self::assertCount(1, $crawler->filter('tr[data-bs-toggle="collapse"]'));

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
        self::assertCount(0, $crawler->filter('tr[data-bs-toggle="collapse"]'));
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

    public function testPackagePageRendersEveryVersionWithItsDeletionState(): void
    {
        // the list is built from VersionListItem rather than full Version entities, so the
        // soft-delete state has to survive that projection - a partial load that silently
        // dropped it would render deleted versions as live ones
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $package->setCrawledAt(new \DateTimeImmutable());

        $versions = [];
        for ($i = 0; $i < 25; $i++) {
            $versions[] = $this->createStableVersion($package, '1.0.'.$i);
        }
        $pulled = $this->createStableVersion($package, '2.0.0');
        $pulled->setSoftDeletedAt(new \DateTimeImmutable('2026-02-03 04:05:06'));
        $pulled->setDeletionReason(VersionDeletionReason::DeletedByMaintainer);
        $versions[] = $pulled;

        $this->store($package, ...$versions);

        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();

        $rendered = $crawler->filter('ul.versions li.version')->each(static fn ($li): string => (string) $li->attr('data-version-id'));
        self::assertCount(26, $rendered, 'every version must ship in the initial HTML, the list is only clipped by CSS');
        self::assertSame('2.0.0', $rendered[0]);
        self::assertContains('1.0.24', $rendered);
        self::assertContains('1.0.0', $rendered);

        self::assertCount(1, $crawler->filter('ul.versions li.version-soft-deleted'));
        self::assertSame(
            'Deleted by maintainer on 2026-02-03 04:05:06 UTC',
            $crawler->filter('ul.versions li.version-soft-deleted .deletion-alert')->attr('title')
        );
    }

    public function testPackagePageHidesHiddenVersionsFromNonStaff(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN']);
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $package->setCrawledAt(new \DateTimeImmutable());

        $visible = $this->createStableVersion($package, '1.0.0');
        $hidden = $this->createStableVersion($package, '1.1.0');
        $hidden->setSoftDeletedAt(new \DateTimeImmutable());
        $hidden->setDeletionReason(VersionDeletionReason::Hidden);

        $this->store($admin, $package, $visible, $hidden);

        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['1.0.0'],
            $crawler->filter('ul.versions li.version')->each(static fn ($li): string => (string) $li->attr('data-version-id'))
        );

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/packages/test/pkg');
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['1.1.0', '1.0.0'],
            $crawler->filter('ul.versions li.version')->each(static fn ($li): string => (string) $li->attr('data-version-id'))
        );
    }

    public function testStatsJsonDoesNotExposeReleaseChart(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $package->setCrawledAt(new \DateTimeImmutable());
        $version = $this->createStableVersion($package, '1.0.0');
        $this->store($package, $version);

        $this->client->request('GET', '/packages/test/pkg/stats.json');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['downloads', 'versions', 'average', 'date'], array_keys($data));
        self::assertSame(['1.0.0'], $data['versions']);
    }

    public function testStatsPageReleaseChartIgnoresSoftDeletedDevAndPreStatsVersions(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $package->setCrawledAt(new \DateTimeImmutable());

        $counted = $this->createStableVersion($package, '1.0.0');
        $counted->setReleasedAt(new \DateTimeImmutable('2024-03-10'));
        $alsoCounted = $this->createStableVersion($package, '1.0.1');
        $alsoCounted->setReleasedAt(new \DateTimeImmutable('2024-03-20'));
        $softDeleted = $this->createStableVersion($package, '1.1.0');
        $softDeleted->setReleasedAt(new \DateTimeImmutable('2024-04-05'));
        $softDeleted->setSoftDeletedAt(new \DateTimeImmutable());
        $preStats = $this->createStableVersion($package, '0.9.0');
        $preStats->setReleasedAt(new \DateTimeImmutable('2005-01-01'));
        $dev = $this->createStableVersion($package, 'dev-main');
        $dev->setDevelopment(true);
        $dev->setReleasedAt(new \DateTimeImmutable('2024-05-01'));

        $this->store($package, $counted, $alsoCounted, $softDeleted, $preStats, $dev);

        $this->client->request('GET', '/packages/test/pkg/stats');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'initReleaseStats(\'.js-release-stats\', {"2024-03":2},',
            (string) $this->client->getResponse()->getContent()
        );
    }

    public function testMajorVersionStatsSumsEveryVersionInTheSeries(): void
    {
        $this->createPackageWithDownloads();

        $data = $this->requestStatsJson('/packages/test/pkg/stats/major/all.json');

        self::assertSame(['2026-01-01', '2026-01-02'], $data['labels']);
        // 1.0.0 + 1.1.0 on the first day, 1.0.0 alone on the second; 1.9.x-dev must not be counted
        self::assertSame([15, 1], $data['values']['1']);
        self::assertSame([0, 7], $data['values']['2']);
        // 3.0.0 has a download row with empty data, so its series is still drawn, as zeroes
        self::assertSame([0, 0], $data['values']['3']);
        // 1.9.x-dev normalizes to 1.9.9999999.9999999-dev, so only development = 0 excludes it.
        // Keys are ints because json_decode casts numeric object keys.
        self::assertSame([1, 2, 3], array_keys($data['values']));
    }

    public function testSingleMajorVersionStatsSeriesByMinorAndSkipsVersionsWithoutDownloads(): void
    {
        $this->createPackageWithDownloads();

        $data = $this->requestStatsJson('/packages/test/pkg/stats/major/1.json');

        self::assertSame(['2026-01-01', '2026-01-02'], $data['labels']);
        self::assertSame([10, 1], $data['values']['1.0']);
        self::assertSame([5, 0], $data['values']['1.1']);
        // 1.2.0 exists but has no download row, so it is not a series at all
        self::assertSame(['1.0', '1.1'], array_keys($data['values']));
    }

    public function testVersionStatsStillReturnsASingleSeries(): void
    {
        $this->createPackageWithDownloads();

        $data = $this->requestStatsJson('/packages/test/pkg/stats/1.0.0.json');

        self::assertSame([10, 1], $data['values']['1.0.0']);
    }

    public function testPackageStatsReadsThePackageLevelDownloadRow(): void
    {
        $this->createPackageWithDownloads();

        $data = $this->requestStatsJson('/packages/test/pkg/stats/all.json');

        self::assertSame(['2026-01-01', '2026-01-02'], $data['labels']);
        // the package row is stored independently of the version rows, so it is not their sum
        self::assertSame([20, 3], $data['values']['test/pkg']);
    }

    public function testMajorVersionStatsAveragesOverMultiDayBuckets(): void
    {
        $this->createPackageWithDownloads();

        $data = $this->requestStatsJson('/packages/test/pkg/stats/major/all.json', 'weekly');

        // both days fall in one weekly bucket, so each series is ceil(sum / 2), not the sum
        self::assertSame(['2026-01-01'], $data['labels']);
        self::assertSame([8], $data['values']['1']);
        self::assertSame([4], $data['values']['2']);
        self::assertSame([0], $data['values']['3']);
    }

    public function testMajorVersionStatsSkipsRowsWhoseDataIsNotAnArray(): void
    {
        $package = $this->createPackageWithDownloads();

        // a corrupt or NULL blob decodes to something that is not an array, which must not 500
        $this->setRawDownloadData($this->findVersion($package, '2.0.0')->getId(), Download::TYPE_VERSION, 'null');

        $data = $this->requestStatsJson('/packages/test/pkg/stats/major/all.json');

        // the series is still drawn, just without any downloads in it
        self::assertSame([0, 0], $data['values']['2']);
    }

    public function testMajorVersionStatsIgnoresDownloadRowsPointingAtAnotherPackagesVersion(): void
    {
        $package = $this->createPackageWithDownloads();

        $other = self::createPackage('other/pkg', 'https://example.org/other');
        $other->setCrawledAt(new \DateTimeImmutable());
        $otherVersion = $this->createStableVersion($other, '9.0.0');
        $this->store($other, $otherVersion);
        $this->store($this->createDownload($other, $otherVersion->getId(), Download::TYPE_VERSION, ['20260101' => 500]));

        // download.package_id is only written when the row is created, so it can end up pointing at
        // the wrong package; package_version is what decides whose chart a version appears in
        $this->getEM()->getConnection()->executeStatement(
            'UPDATE download SET package_id = :package WHERE id = :id AND type = :type',
            ['package' => $package->getId(), 'id' => $otherVersion->getId(), 'type' => Download::TYPE_VERSION]
        );

        $data = $this->requestStatsJson('/packages/test/pkg/stats/major/all.json');

        self::assertArrayNotHasKey('9', $data['values']);
        self::assertSame([15, 1], $data['values']['1']);
    }

    public function testMajorVersionStatsKeepsVersionsWhoseDownloadRowHasNoPackageId(): void
    {
        $package = $this->createPackageWithDownloads();

        // package_version owns the relation, so a download row that never got a package_id (or got
        // a stale one) must still be counted rather than silently dropped from the chart
        $this->getEM()->getConnection()->executeStatement(
            'UPDATE download SET package_id = NULL WHERE id = :id AND type = :type',
            ['id' => $this->findVersion($package, '2.0.0')->getId(), 'type' => Download::TYPE_VERSION]
        );

        $data = $this->requestStatsJson('/packages/test/pkg/stats/major/all.json');

        self::assertSame([0, 7], $data['values']['2']);
    }

    public function testMajorVersionStatsSkipsMalformedDataEntries(): void
    {
        $package = $this->createPackageWithDownloads();

        // an array value would otherwise count as 1 download, and a non-Ymd key would be stored and
        // then never read; the valid entry alongside them proves only the bad ones are dropped
        $this->setRawDownloadData(
            $this->findVersion($package, '2.0.0')->getId(),
            Download::TYPE_VERSION,
            '{"20260101": [1, 2], "foo": 500, "20260102": 9}'
        );
        $this->setRawDownloadData(
            $this->findVersion($package, '1.1.0')->getId(),
            Download::TYPE_VERSION,
            '{"20260101": "abc"}'
        );

        $data = $this->requestStatsJson('/packages/test/pkg/stats/major/all.json');

        self::assertSame([0, 9], $data['values']['2']);
        // 1.1.0 contributes nothing now, so the "1" series is just 1.0.0
        self::assertSame([10, 1], $data['values']['1']);
    }

    public function testVersionAndPackageStatsSurviveAMalformedDataEntry(): void
    {
        $package = $this->createPackageWithDownloads();

        // these two routes read the hydrated entity rather than the repository, so they need the
        // guard in the sum loop to avoid an "int + array" TypeError
        $this->setRawDownloadData(
            $this->findVersion($package, '2.0.0')->getId(),
            Download::TYPE_VERSION,
            '{"20260101": [1, 2], "20260102": 9}'
        );
        $this->setRawDownloadData($package->getId(), Download::TYPE_PACKAGE, '{"20260101": [1, 2], "20260102": 3}');
        // these routes load the entity, so the identity map has to go before they see the raw write
        $this->getEM()->clear();

        $data = $this->requestStatsJson('/packages/test/pkg/stats/2.0.0.json');
        self::assertSame([0, 9], $data['values']['2.0.0']);

        $data = $this->requestStatsJson('/packages/test/pkg/stats/all.json');
        self::assertSame([0, 3], $data['values']['test/pkg']);
    }

    public function testMajorVersionStatsValuesAreAlwaysAJsonObject(): void
    {
        $package = $this->createPackageWithDownloads();
        $zero = $this->createStableVersion($package, '0.9.0');
        $this->store($zero);
        $this->store($this->createDownload($package, $zero->getId(), Download::TYPE_VERSION, ['20260101' => 4]));

        $this->client->request('GET', '/packages/test/pkg/stats/major/all.json?from=2026-01-01&to=2026-01-02&average=daily');
        self::assertResponseIsSuccessful();

        // series named 0, 1, ... are int keys in PHP, so without the cast json_encode emits a list
        self::assertStringContainsString('"values":{"0":', (string) $this->client->getResponse()->getContent());
    }

    private function findVersion(Package $package, string $version): Version
    {
        $found = $this->getEM()->getRepository(Version::class)->findOneBy(['package' => $package, 'version' => $version]);
        self::assertInstanceOf(Version::class, $found);

        return $found;
    }

    /**
     * Writes the json column directly, as the entity's array type cannot express a corrupt blob.
     */
    private function setRawDownloadData(int $id, int $type, string $json): void
    {
        $this->getEM()->getConnection()->executeStatement(
            'UPDATE download SET data = :data WHERE id = :id AND type = :type',
            ['data' => $json, 'id' => $id, 'type' => $type]
        );
    }

    private function createPackageWithDownloads(): Package
    {
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $package->setCrawledAt(new \DateTimeImmutable());

        $versions = [];
        foreach (['1.0.0', '1.1.0', '1.2.0', '2.0.0', '3.0.0', '1.9.x-dev'] as $version) {
            $versions[$version] = $this->createStableVersion($package, $version);
        }
        $versions['1.9.x-dev']->setDevelopment(true);

        $this->store($package, ...array_values($versions));

        $downloads = [
            '1.0.0' => ['20260101' => 10, '20260102' => 1],
            '1.1.0' => ['20260101' => 5],
            '2.0.0' => ['20260102' => 7],
            // would swamp the "1" series if development versions were not filtered out
            '1.9.x-dev' => ['20260101' => 1000],
            // a row that exists but carries no data at all
            '3.0.0' => [],
            // 1.2.0 deliberately gets no download row
        ];

        // deliberately not the sum of the version rows, so that mixing the two up is visible
        $rows = [$this->createDownload($package, $package->getId(), Download::TYPE_PACKAGE, ['20260101' => 20, '20260102' => 3])];
        foreach ($downloads as $version => $data) {
            $rows[] = $this->createDownload($package, $versions[$version]->getId(), Download::TYPE_VERSION, $data);
        }
        $this->store($rows);

        return $package;
    }

    /**
     * @param array<numeric-string, int> $data
     */
    private function createDownload(Package $package, int $id, int $type, array $data): Download
    {
        $download = new Download();
        $download->setId($id);
        $download->setType($type);
        $download->setPackage($package);
        $download->setData($data);
        $download->setLastUpdated(new \DateTimeImmutable());
        $download->computeSum();

        return $download;
    }

    /**
     * The date range is pinned so createDatePoints() yields exactly one Ymd key per label for the
     * daily average, which keeps the expected values plain sums.
     *
     * @return array{labels: list<string>, values: array<string, list<int>>, average: string}
     */
    private function requestStatsJson(string $path, string $average = 'daily'): array
    {
        $this->client->request('GET', $path.'?from=2026-01-01&to=2026-01-02&average='.$average);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
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

    /**
     * /packages/list.json is streamed, so $client->getResponse()->getContent() returns false and the
     * body is only reachable via getInternalResponse(), which HttpKernelBrowser captures with ob_start().
     */
    private function requestListJson(string $query, string $method = 'GET'): string
    {
        $this->client->request($method, '/packages/list.json?'.$query);

        self::assertInstanceOf(StreamedJsonResponse::class, $this->client->getResponse());
        self::assertResponseIsSuccessful();

        return (string) $this->client->getInternalResponse()->getContent();
    }

    public function testListJsonWithFieldsStreamsByteIdenticalJson(): void
    {
        $withReplacement = self::createPackage('listvendor/abandoned', 'https://example.org/abandoned');
        $withReplacement->setType('library');
        $withReplacement->setAbandoned(true);
        $withReplacement->setReplacementPackage('other/pkg');
        $active = self::createPackage('listvendor/active', 'https://example.org/active');
        $active->setType('library');
        $this->store($withReplacement, $active);

        $body = $this->requestListJson('vendor=listvendor&fields[]=type&fields[]=abandoned');

        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString('s-maxage=300', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertResponseHeaderSame('X-Accel-Expires', '300');

        // StreamedJsonResponse defaults to the same encoding options as JsonResponse, so streaming
        // must not change a single byte of the payload - including the escaped slashes in names
        self::assertSame(
            json_encode(['packages' => [
                'listvendor/abandoned' => ['type' => 'library', 'abandoned' => 'other/pkg'],
                'listvendor/active' => ['type' => 'library', 'abandoned' => false],
            ]], JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES),
            $body,
        );
        self::assertStringContainsString('listvendor/abandoned', $body);
    }

    public function testListJsonWithFieldsAndNoMatchesEmitsEmptyArray(): void
    {
        self::assertSame('{"packages":[]}', $this->requestListJson('vendor=nosuchvendor&fields[]=type'));
    }

    public function testListJsonEmitsPackageNamesAsJsonArray(): void
    {
        $packages = [];
        foreach (['listvendor/aaa', 'listvendor/abb', 'listvendor/bbb'] as $name) {
            $package = self::createPackage($name, 'https://example.org/'.$name);
            $package->setType('library');
            $packages[] = $package;
        }
        $this->store(...$packages);

        $body = $this->requestListJson('vendor=listvendor');

        self::assertSame(
            json_encode(['packageNames' => ['listvendor/aaa', 'listvendor/abb', 'listvendor/bbb']], JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES),
            $body,
        );
    }

    public function testListJsonFilterEmitsJsonArrayNotObject(): void
    {
        $packages = [];
        foreach (['listvendor/aaa', 'listvendor/abb', 'listvendor/bbb'] as $name) {
            $package = self::createPackage($name, 'https://example.org/'.$name);
            $package->setType('library');
            $packages[] = $package;
        }
        $this->store(...$packages);

        // asserted on the raw string: a stray `yield $key => $name` would emit an object whose
        // json_decode() looks identical to the array's, so decoding here would hide the regression
        self::assertSame(
            json_encode(['packageNames' => ['listvendor/abb', 'listvendor/bbb']], JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES),
            $this->requestListJson('vendor=listvendor&filter=listvendor/*bb'),
        );
    }

    public function testListJsonHeadRequestSkipsTheStreamEntirely(): void
    {
        $package = self::createPackage('listvendor/aaa', 'https://example.org/aaa');
        $package->setType('library');
        $this->store($package);

        self::assertSame('', $this->requestListJson('vendor=listvendor&fields[]=type', 'HEAD'));
    }

    /**
     * The unfiltered listing is the one the CDN fans out to every edge, so it is served from the
     * prebuilt blob rather than read and sorted out of Redis per request.
     */
    public function testUnfilteredListJsonServesThePrebuiltBlobGzipped(): void
    {
        $cache = self::getService(PackageListCache::class);
        $names = ['listvendor/aaa', 'listvendor/bbb'];
        $cache->write($names, 1);

        try {
            $this->client->request('GET', '/packages/list.json', [], [], ['HTTP_ACCEPT_ENCODING' => 'gzip, deflate']);

            $response = $this->client->getResponse();
            self::assertInstanceOf(Response::class, $response);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/json');
            self::assertResponseHeaderSame('Content-Encoding', 'gzip');
            // without Vary the CDN could hand this body to a client that never asked for gzip.
            // getVary() parses every Vary line; headers->get() would only return the first.
            self::assertContains('Accept-Encoding', $response->getVary());
            self::assertContains('Origin', $response->getVary(), 'the CORS Vary must survive');
            self::assertStringContainsString('s-maxage=300', (string) $response->headers->get('Cache-Control'));
            self::assertResponseHeaderSame('X-Accel-Expires', '300');

            $body = (string) $response->getContent();
            self::assertSame((string) \strlen($body), $response->headers->get('Content-Length'));
            self::assertSame(
                json_encode(['packageNames' => $names], JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES),
                gzdecode($body),
            );
        } finally {
            $cache->clear();
        }
    }

    public function testUnfilteredListJsonDecompressesForClientsThatRefuseGzip(): void
    {
        $cache = self::getService(PackageListCache::class);
        $names = ['listvendor/aaa', 'listvendor/bbb'];
        $cache->write($names, 1);

        try {
            // q=0 explicitly refuses gzip, so the body has to go out plain
            $this->client->request('GET', '/packages/list.json', [], [], ['HTTP_ACCEPT_ENCODING' => 'gzip;q=0']);

            self::assertResponseIsSuccessful();
            self::assertFalse($this->client->getResponse()->headers->has('Content-Encoding'));
            self::assertSame(
                json_encode(['packageNames' => $names], JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES),
                (string) $this->client->getResponse()->getContent(),
            );
        } finally {
            $cache->clear();
        }
    }

    public function testUnfilteredListJsonFallsBackToTheLivePathWithoutABlob(): void
    {
        $cache = self::getService(PackageListCache::class);
        $cache->clear();

        $package = self::createPackage('listvendor/fallback', 'https://example.org/fallback');
        $this->store($package);
        self::getService(ProviderManager::class)->insertPackage($package);

        try {
            $body = $this->requestListJson('');

            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertContains('listvendor/fallback', $decoded['packageNames']);
        } finally {
            $cache->clear();
        }
    }

    public function testFilteredListJsonIgnoresTheBlob(): void
    {
        $cache = self::getService(PackageListCache::class);
        // a blob that does not match the DB at all, to prove the filtered branches never read it
        $cache->write(['blob/only'], 1);

        try {
            $providerManager = self::getService(ProviderManager::class);
            $packages = [];
            foreach (['listvendor/aaa', 'listvendor/bbb'] as $name) {
                $package = self::createPackage($name, 'https://example.org/'.$name);
                $package->setType('library');
                $packages[] = $package;
            }
            $this->store(...$packages);
            // the filter-only branch reads set:packages rather than the DB, and store() does not
            // go through the insert path that populates it
            foreach ($packages as $package) {
                $providerManager->insertPackage($package);
            }

            self::assertSame(
                json_encode(['packageNames' => ['listvendor/aaa', 'listvendor/bbb']], JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES),
                $this->requestListJson('vendor=listvendor'),
            );
            self::assertSame(
                json_encode(['packageNames' => ['listvendor/bbb']], JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES),
                $this->requestListJson('filter=listvendor/bbb'),
            );
        } finally {
            $cache->clear();
        }
    }
}
