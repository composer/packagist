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

use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\PackageRepository;
use App\Tests\IntegrationTestCase;

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
}
