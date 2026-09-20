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

use App\Entity\Dependent;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\PackageRepository;
use App\Entity\Suggester;
use App\Entity\Vendor;
use App\Tests\IntegrationTestCase;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;
use Predis\ClientException;

class PackageRepositoryTest extends IntegrationTestCase
{
    private PackageRepository $packageRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packageRepository = self::getEM()->getRepository(Package::class);
    }

    public function testGetPackageNamesExcludesSuppressedFrozenPackages(): void
    {
        $active = self::createPackage('vendor/active', 'https://example.org/active');
        $temporary = self::createPackage('vendor/temporary', 'https://example.org/temporary');
        $temporary->freeze(PackageFreezeReason::Temporary);
        $spam = self::createPackage('vendor/spam', 'https://example.org/spam');
        $spam->freeze(PackageFreezeReason::Spam);
        $malware = self::createPackage('vendor/malware', 'https://example.org/malware');
        $malware->freeze(PackageFreezeReason::Malware);
        $this->store($active, $temporary, $spam, $malware);

        $names = $this->packageRepository->getPackageNames();

        self::assertContains('vendor/active', $names);
        self::assertContains('vendor/temporary', $names, 'gentle (non-suppressing) freezes stay listed');
        self::assertNotContains('vendor/spam', $names);
        self::assertNotContains('vendor/malware', $names, 'malware is a suppressing reason and must be excluded like spam');
    }

    public function testIteratePackageNamesByTypeAndVendorExcludesSuppressedFrozenPackages(): void
    {
        // Mirrors getPackageNames(): the /packages/list.json filtered branch must agree with it.
        $active = self::createPackage('vendor/active', 'https://example.org/active');
        $active->setType('library');
        $temporary = self::createPackage('vendor/temporary', 'https://example.org/temporary');
        $temporary->setType('library');
        $temporary->freeze(PackageFreezeReason::Temporary);
        $spam = self::createPackage('vendor/spam', 'https://example.org/spam');
        $spam->setType('library');
        $spam->freeze(PackageFreezeReason::Spam);
        $malware = self::createPackage('vendor/malware', 'https://example.org/malware');
        $malware->setType('library');
        $malware->freeze(PackageFreezeReason::Malware);
        $this->store($active, $temporary, $spam, $malware);

        $names = iterator_to_array($this->packageRepository->iteratePackageNamesByTypeAndVendor('library', 'vendor'));

        self::assertContains('vendor/active', $names);
        self::assertContains('vendor/temporary', $names, 'gentle (non-suppressing) freezes stay listed');
        self::assertNotContains('vendor/spam', $names);
        self::assertNotContains('vendor/malware', $names);
    }

    public function testIteratePackageNamesByTypeAndVendorSortsInSql(): void
    {
        // Pins the ordering contract for the streamed branch: utf8mb4_unicode_ci, not PHP's
        // SORT_STRING|SORT_FLAG_CASE, which would put `_` after the digits rather than first.
        $packages = [];
        foreach (['sortvendor/ab', 'sortvendor/a-b', 'sortvendor/a_b', 'sortvendor/a0b', 'sortvendor/a.b'] as $name) {
            $package = self::createPackage($name, 'https://example.org/'.$name);
            $package->setType('library');
            $packages[] = $package;
        }
        $this->store(...$packages);

        $names = iterator_to_array($this->packageRepository->iteratePackageNamesByTypeAndVendor('library', 'sortvendor'));

        self::assertSame(['sortvendor/a_b', 'sortvendor/a-b', 'sortvendor/a.b', 'sortvendor/a0b', 'sortvendor/ab'], $names);
    }

    public function testIteratePackagesWithFieldsCollapsesAbandonedIntoReplacementOrBool(): void
    {
        $withReplacement = self::createPackage('fieldvendor/abandoned-with-replacement', 'https://example.org/awr');
        $withReplacement->setType('library');
        $withReplacement->setAbandoned(true);
        $withReplacement->setReplacementPackage('other/pkg');
        $bare = self::createPackage('fieldvendor/abandoned-bare', 'https://example.org/ab');
        $bare->setType('library');
        $bare->setAbandoned(true);
        $active = self::createPackage('fieldvendor/active', 'https://example.org/active');
        $active->setType('library');
        $this->store($withReplacement, $bare, $active);

        $packages = iterator_to_array($this->packageRepository->iteratePackagesWithFields(['vendor' => 'fieldvendor'], ['type', 'abandoned']));

        // name-keyed, ordered by name, and replacementPackage is folded into abandoned rather than exposed
        self::assertSame([
            'fieldvendor/abandoned-bare' => ['type' => 'library', 'abandoned' => true],
            'fieldvendor/abandoned-with-replacement' => ['type' => 'library', 'abandoned' => 'other/pkg'],
            'fieldvendor/active' => ['type' => 'library', 'abandoned' => false],
        ], $packages);
    }

    public function testIteratePackagesWithFieldsExcludesSuppressedFrozenPackages(): void
    {
        // Mirrors getPackageNames(): every /packages/list.json branch must agree on what is listed.
        $active = self::createPackage('vendor/active', 'https://example.org/active');
        $temporary = self::createPackage('vendor/temporary', 'https://example.org/temporary');
        $temporary->freeze(PackageFreezeReason::Temporary);
        $spam = self::createPackage('vendor/spam', 'https://example.org/spam');
        $spam->freeze(PackageFreezeReason::Spam);
        $malware = self::createPackage('vendor/malware', 'https://example.org/malware');
        $malware->freeze(PackageFreezeReason::Malware);
        $this->store($active, $temporary, $spam, $malware);

        $names = array_keys(iterator_to_array($this->packageRepository->iteratePackagesWithFields([], ['repository'])));

        self::assertContains('vendor/active', $names);
        self::assertContains('vendor/temporary', $names, 'gentle (non-suppressing) freezes stay listed');
        self::assertNotContains('vendor/spam', $names);
        self::assertNotContains('vendor/malware', $names, 'malware is a suppressing reason and must be excluded like spam');
    }

    public function testGetQueryBuilderForNewestPackagesExcludesSuppressedButKeepsGentleFreezes(): void
    {
        // Discovery surfaces (newest-packages feed, homepage explore) mirror search/list.json:
        // suppressed packages are hidden, gentle freezes stay listed.
        $active = self::createPackage('vendor/active', 'https://example.org/active');
        $temporary = self::createPackage('vendor/temporary', 'https://example.org/temporary');
        $temporary->freeze(PackageFreezeReason::Temporary);
        $spam = self::createPackage('vendor/spam', 'https://example.org/spam');
        $spam->freeze(PackageFreezeReason::Spam);
        $malware = self::createPackage('vendor/malware', 'https://example.org/malware');
        $malware->freeze(PackageFreezeReason::Malware);
        $this->store($active, $temporary, $spam, $malware);

        $names = array_map(
            static fn (Package $p): string => $p->getName(),
            $this->packageRepository->getQueryBuilderForNewestPackages()->getQuery()->getResult(),
        );

        self::assertContains('vendor/active', $names);
        self::assertContains('vendor/temporary', $names, 'gentle freezes stay discoverable, matching search/list.json');
        self::assertNotContains('vendor/spam', $names);
        self::assertNotContains('vendor/malware', $names);
    }

    public function testGetStalePackagesForDumpingV2ExcludesSuppressedButKeepsGentleFreezes(): void
    {
        // The dump regenerates served metadata from the DB (no repo fetch), so gentle freezes must
        // stay dumpable — only suppressed packages are excluded, matching V2Dumper::dump()'s guard.
        // Freshly-created packages have dumpedAtV2 = NULL, so they all qualify as stale.
        $active = self::createPackage('vendor/active', 'https://example.org/active');
        $temporary = self::createPackage('vendor/temporary', 'https://example.org/temporary');
        $temporary->freeze(PackageFreezeReason::Temporary);
        $spam = self::createPackage('vendor/spam', 'https://example.org/spam');
        $spam->freeze(PackageFreezeReason::Spam);
        $malware = self::createPackage('vendor/malware', 'https://example.org/malware');
        $malware->freeze(PackageFreezeReason::Malware);
        $this->store($active, $temporary, $spam, $malware);

        $ids = $this->packageRepository->getStalePackagesForDumpingV2();

        self::assertContains($active->getId(), $ids);
        self::assertContains($temporary->getId(), $ids, 'gentle freezes keep their served metadata maintained');
        self::assertNotContains($spam->getId(), $ids);
        self::assertNotContains($malware->getId(), $ids);
    }

    public function testGetFilteredQueryBuilderExcludesSuppressedByDefault(): void
    {
        $active = self::createPackage('vendor/active', 'https://example.org/active');
        $spam = self::createPackage('vendor/spam', 'https://example.org/spam');
        $spam->freeze(PackageFreezeReason::Spam);
        $malware = self::createPackage('vendor/malware', 'https://example.org/malware');
        $malware->freeze(PackageFreezeReason::Malware);
        $this->store($active, $spam, $malware);

        $names = static fn (array $packages): array => array_map(static fn (Package $p): string => $p->getName(), $packages);

        $default = $names($this->packageRepository->getFilteredQueryBuilder([], true)->getQuery()->getResult());
        self::assertContains('vendor/active', $default);
        self::assertNotContains('vendor/spam', $default);
        self::assertNotContains('vendor/malware', $default);

        $withFrozen = $names($this->packageRepository->getFilteredQueryBuilder([], true, includeFrozen: true)->getQuery()->getResult());
        self::assertContains('vendor/spam', $withFrozen);
        self::assertContains('vendor/malware', $withFrozen);
    }

    public function testGetDependentCountIsCachedPerType(): void
    {
        $requirer = self::createPackage('test/requirer', 'https://example.org/requirer');
        $devRequirer = self::createPackage('test/dev-requirer', 'https://example.org/dev-requirer');
        $this->store($requirer, $devRequirer);
        $this->store(
            new Dependent($requirer, 'test/required', Dependent::TYPE_REQUIRE),
            new Dependent($devRequirer, 'test/required', Dependent::TYPE_REQUIRE_DEV),
        );

        self::assertSame(2, $this->packageRepository->getDependentCount('test/required'));
        self::assertSame(1, $this->packageRepository->getDependentCount('test/required', Dependent::TYPE_REQUIRE));
        self::assertSame(1, $this->packageRepository->getDependentCount('test/required', Dependent::TYPE_REQUIRE_DEV));

        // each variant gets its own key, so the type filter cannot be served from the unfiltered count
        self::assertSame('2', $this->redisCache()->get('dep-count:test/required:all'));
        self::assertSame('1', $this->redisCache()->get('dep-count:test/required:'.Dependent::TYPE_REQUIRE));
        self::assertSame('1', $this->redisCache()->get('dep-count:test/required:'.Dependent::TYPE_REQUIRE_DEV));
    }

    public function testGetDependentCountReadsTheCacheAndIgnoresNameCasing(): void
    {
        $this->redisCache()->set('dep-count:test/required:all', '42');

        self::assertSame(42, $this->packageRepository->getDependentCount('test/required'));
        // packageName uses a case-insensitive collation, so casing must not produce a second entry
        self::assertSame(42, $this->packageRepository->getDependentCount('Test/Required'));
    }

    public function testGetSuggestCountIsCached(): void
    {
        $suggester = self::createPackage('test/suggester', 'https://example.org/suggester');
        $this->store($suggester);
        $this->store(new Suggester($suggester, 'test/suggested'));

        self::assertSame(1, $this->packageRepository->getSuggestCount('test/suggested'));
        self::assertSame('1', $this->redisCache()->get('sug-count:test/suggested'));

        $this->redisCache()->set('sug-count:test/suggested', '7');
        self::assertSame(7, $this->packageRepository->getSuggestCount('test/suggested'));
    }

    public function testCountsBypassTheCacheEntirelyWhenUncached(): void
    {
        $requirer = self::createPackage('test/requirer', 'https://example.org/requirer');
        $this->store($requirer);
        $this->store(new Dependent($requirer, 'test/required', Dependent::TYPE_REQUIRE));

        $this->redisCache()->set('dep-count:test/required:all', '42');

        // the listing pager needs a count matching the rows queried alongside it, so no read
        self::assertSame(1, $this->packageRepository->getDependentCount('test/required', cached: false));
        self::assertSame(0, $this->packageRepository->getSuggestCount('test/required', cached: false));

        // and no write back either, which is what keeps unvalidated route names out of Redis
        self::assertSame('42', $this->redisCache()->get('dep-count:test/required:all'));
        self::assertNull($this->redisCache()->get('sug-count:test/required'));
    }

    public function testCountsFallBackToTheQueryWhenTheCacheIsDown(): void
    {
        $requirer = self::createPackage('test/requirer', 'https://example.org/requirer');
        $this->store($requirer);
        $this->store(new Dependent($requirer, 'test/required', Dependent::TYPE_REQUIRE));

        // built by hand as the container's repository already holds the working client
        $repo = new PackageRepository(self::getService(ManagerRegistry::class), $this->createBrokenRedis());

        self::assertSame(1, $repo->getDependentCount('test/required'));
        self::assertSame(0, $repo->getSuggestCount('test/required'));
    }

    public function testCountsCacheZeroSoUnknownPackagesDoNotRequeryEveryPageView(): void
    {
        self::assertSame(0, $this->packageRepository->getDependentCount('test/nothing-requires-this'));
        self::assertSame(0, $this->packageRepository->getSuggestCount('test/nothing-requires-this'));

        self::assertSame('0', $this->redisCache()->get('dep-count:test/nothing-requires-this:all'));
        self::assertSame('0', $this->redisCache()->get('sug-count:test/nothing-requires-this'));
    }

    public function testZeroCountsExpireSoonerThanRealOnes(): void
    {
        $requirer = self::createPackage('test/requirer', 'https://example.org/requirer');
        $this->store($requirer);
        $this->store(new Dependent($requirer, 'test/required', Dependent::TYPE_REQUIRE));

        $this->packageRepository->getDependentCount('test/required');
        $this->packageRepository->getDependentCount('test/nothing-requires-this');

        // a real count can sit for a day, a zero must not hide a package's first dependent that
        // long. Reading the TTL also pins that these are set with an expiry at all.
        $maxZeroTtl = 3600 + 600;
        self::assertGreaterThan($maxZeroTtl, $this->redisCache()->ttl('dep-count:test/required:all'));
        self::assertLessThanOrEqual($maxZeroTtl, $this->redisCache()->ttl('dep-count:test/nothing-requires-this:all'));
    }

    private function createBrokenRedis(): Client
    {
        return new class () extends Client {
            public function __call($commandID, $arguments): mixed
            {
                throw new ClientException('redis is down');
            }
        };
    }

    public function testGetDependentsListsTheRequiringPackages(): void
    {
        $alpha = self::createPackage('test/alpha', 'https://example.org/alpha');
        $beta = self::createPackage('test/beta', 'https://example.org/beta');
        $this->store($alpha, $beta);
        $this->store(
            new Dependent($beta, 'test/required', Dependent::TYPE_REQUIRE),
            new Dependent($alpha, 'test/required', Dependent::TYPE_REQUIRE_DEV),
        );

        $names = array_column($this->packageRepository->getDependents('test/required'), 'name');
        self::assertSame(['test/alpha', 'test/beta'], $names, 'ordered by name by default');

        $requireOnly = array_column(
            $this->packageRepository->getDependents('test/required', type: Dependent::TYPE_REQUIRE),
            'name',
        );
        self::assertSame(['test/beta'], $requireOnly);

        $secondPage = $this->packageRepository->getDependents('test/required', offset: 1, limit: 1);
        self::assertSame(['test/beta'], array_column($secondPage, 'name'));
    }

    public function testGetDependentsCanSkipTheSort(): void
    {
        // The unsorted variant drops the download join with the ORDER BY, so it has to still return
        // the same rows - only their order is given up.
        $alpha = self::createPackage('test/alpha', 'https://example.org/alpha');
        $beta = self::createPackage('test/beta', 'https://example.org/beta');
        $spam = self::createPackage('test/spam', 'https://example.org/spam');
        $spam->freeze(PackageFreezeReason::Spam);
        $this->store($alpha, $beta, $spam);
        $this->store(
            new Dependent($alpha, 'test/required', Dependent::TYPE_REQUIRE),
            new Dependent($beta, 'test/required', Dependent::TYPE_REQUIRE),
            new Dependent($spam, 'test/required', Dependent::TYPE_REQUIRE),
        );

        $unsorted = array_column($this->packageRepository->getDependents('test/required', orderBy: null), 'name');
        sort($unsorted);

        self::assertSame(['test/alpha', 'test/beta'], $unsorted, 'same rows as the sorted call, suppressed ones still hidden');
    }

    public function testGetDependentCountDedupesByPackage(): void
    {
        // A package requiring the same name in both require and require-dev has two dependent rows
        // but is one entry in the listing, and this count is what paginates that listing.
        $both = self::createPackage('test/both', 'https://example.org/both');
        $this->store($both);
        $this->store(
            new Dependent($both, 'test/required', Dependent::TYPE_REQUIRE),
            new Dependent($both, 'test/required', Dependent::TYPE_REQUIRE_DEV),
        );

        self::assertSame(1, $this->packageRepository->getDependentCount('test/required', cached: false));
        self::assertCount(1, $this->packageRepository->getDependents('test/required'), 'the count must agree with the rows');
    }

    public function testListingsHideSuppressedPackages(): void
    {
        // These rows are plain arrays with no frozen key, so the listPackages macro cannot filter
        // them the way it does for entity rows - a spam package requiring a popular one would
        // otherwise be publicly listed under its dependents.
        $spam = self::createPackage('test/spam', 'https://example.org/spam');
        $spam->freeze(PackageFreezeReason::Spam);
        $gone = self::createPackage('test/gone', 'https://example.org/gone');
        $gone->freeze(PackageFreezeReason::Gone);
        $ok = self::createPackage('test/ok', 'https://example.org/ok');
        $this->store($spam, $gone, $ok);
        $this->store(
            new Dependent($spam, 'test/required', Dependent::TYPE_REQUIRE),
            new Dependent($gone, 'test/required', Dependent::TYPE_REQUIRE),
            new Dependent($ok, 'test/required', Dependent::TYPE_REQUIRE),
            new Suggester($spam, 'test/suggested'),
            new Suggester($ok, 'test/suggested'),
        );

        $dependentRows = $this->packageRepository->getDependents('test/required');
        self::assertSame(['test/gone', 'test/ok'], array_column($dependentRows, 'name'), 'only spam/malware are suppressed, not every frozen reason');
        // frozen is selected to drive the filter above, never to be published
        self::assertArrayNotHasKey('frozen', $dependentRows[0]);
        // listPackages() reads type for the PIE badge, and a missing SELECT column is invisible to
        // PHPStan while the docblock still promises it
        self::assertArrayHasKey('type', $dependentRows[0]);

        $suggesterRows = $this->packageRepository->getSuggests('test/suggested');
        self::assertSame(['test/ok'], array_column($suggesterRows, 'name'));
        self::assertArrayNotHasKey('frozen', $suggesterRows[0]);
        self::assertArrayHasKey('type', $suggesterRows[0]);
    }

    public function testGetSuggestsListsTheSuggestingPackages(): void
    {
        $alpha = self::createPackage('test/alpha', 'https://example.org/alpha');
        $beta = self::createPackage('test/beta', 'https://example.org/beta');
        $this->store($alpha, $beta);
        $this->store(new Suggester($beta, 'test/suggested'), new Suggester($alpha, 'test/suggested'));

        $names = array_column($this->packageRepository->getSuggests('test/suggested'), 'name');
        self::assertSame(['test/alpha', 'test/beta'], $names);
    }

    public function testListingQueriesCarryAnAcceptedExecutionTimeHint(): void
    {
        // MySQL answers a malformed, mis-positioned or inapplicable optimizer hint with a warning
        // and ignores the whole /*+ ... */ comment, so the rows coming back prove nothing about the
        // cap being in force. An empty warning list is what does.
        $this->packageRepository->getDependents('test/required');
        self::assertSame([], $this->lastStatementWarnings(), 'MAX_EXECUTION_TIME was not accepted on the dependents query');

        $this->packageRepository->getSuggests('test/suggested');
        self::assertSame([], $this->lastStatementWarnings(), 'MAX_EXECUTION_TIME was not accepted on the suggesters query');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lastStatementWarnings(): array
    {
        return self::getEM()->getConnection()->fetchAllAssociative('SHOW WARNINGS');
    }
}
