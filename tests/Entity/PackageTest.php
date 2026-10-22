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
use App\Entity\Tag;
use App\Entity\User;
use App\Entity\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PackageTest extends TestCase
{
    public function testWasUpdatedInTheLast24Hours(): void
    {
        $package = new Package();
        $this->assertFalse($package->wasUpdatedInTheLast24Hours());

        $package->setUpdatedAt(new \DateTimeImmutable('2019-01-01'));
        $this->assertFalse($package->wasUpdatedInTheLast24Hours());

        $package->setUpdatedAt(new \DateTimeImmutable('now'));
        $this->assertTrue($package->wasUpdatedInTheLast24Hours());
    }

    #[DataProvider('providePackageScenarios')]
    public function testInstallCommand(string $type, string $tag, string $expected): void
    {
        $version = new Version();
        $version->addTag(new Tag($tag));

        $package = new Package();
        $package->setName('vendor/name');
        $package->setType($type);

        self::assertSame($expected, $package->getInstallCommand($version));
    }

    public static function providePackageScenarios(): array
    {
        return [
            'project' => ['project', 'dev', 'composer create-project vendor/name'],
            'library non-dev' => ['library', 'database', 'composer require vendor/name'],
            'library dev' => ['library', 'testing', 'composer require --dev vendor/name'],
        ];
    }

    public function testInstallCommandWithoutVersion(): void
    {
        $package = new Package();
        $package->setName('vendor/name');

        self::assertSame('composer require vendor/name', $package->getInstallCommand());
    }

    public function testFreezeReasonCasesForRole(): void
    {
        self::assertSame(
            [PackageFreezeReason::Spam, PackageFreezeReason::Malware, PackageFreezeReason::Gone, PackageFreezeReason::Temporary],
            PackageFreezeReason::casesForRole(true),
        );
        self::assertSame([], PackageFreezeReason::casesForRole(false));
    }

    public function testSubmittingOnBehalfOfAUserMakesThemTheOnlyMaintainer(): void
    {
        $moderator = new User();
        $assignee = new User();

        $package = new Package();
        $package->addMaintainer($moderator);
        $package->setSubmittedOnBehalfOf($assignee);

        self::assertSame([$assignee], $package->getMaintainers()->toArray());
        self::assertSame($assignee, $package->getSubmittedOnBehalfOf());
    }

    public function testSubmittingOnBehalfOfAUserIsRejectedOnAPersistedPackage(): void
    {
        $package = new Package();
        new \ReflectionProperty($package, 'id')->setValue($package, 42);

        $this->expectException(\LogicException::class);

        $package->setSubmittedOnBehalfOf(new User());
    }

    public function testSortVersionsOrdersNumericallyEqualVersionsTheSameWhateverTheInputOrder(): void
    {
        $released = new \DateTimeImmutable('2020-07-02 09:34:28');
        $padded = self::versionForSort('v0.0.070', '0.0.070.0', $released);
        $unpadded = self::versionForSort('v0.0.70', '0.0.70.0', $released);

        $forward = [$padded, $unpadded];
        $backward = [$unpadded, $padded];
        usort($forward, Package::sortVersions(...));
        usort($backward, Package::sortVersions(...));

        self::assertSame(['v0.0.070', 'v0.0.70'], array_map(static fn (Version $v): string => $v->getVersion(), $forward));
        self::assertSame(['v0.0.070', 'v0.0.70'], array_map(static fn (Version $v): string => $v->getVersion(), $backward));
    }

    public function testSortVersionsPutsTheNewerOfTwoNumericallyEqualVersionsFirst(): void
    {
        $versions = [
            self::versionForSort('v0.0.070', '0.0.070.0', new \DateTimeImmutable('2020-07-01')),
            self::versionForSort('v0.0.70', '0.0.70.0', new \DateTimeImmutable('2020-07-02')),
        ];
        usort($versions, Package::sortVersions(...));

        self::assertSame(['v0.0.70', 'v0.0.070'], array_map(static fn (Version $v): string => $v->getVersion(), $versions));
    }

    private static function versionForSort(string $version, string $normalized, \DateTimeImmutable $releasedAt): Version
    {
        $v = new Version();
        $v->setVersion($version);
        $v->setNormalizedVersion($normalized);
        $v->setReleasedAt($releasedAt);

        return $v;
    }
}
