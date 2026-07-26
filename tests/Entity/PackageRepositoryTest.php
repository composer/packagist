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
