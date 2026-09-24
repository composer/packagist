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

namespace App\Tests\Support\Attributes;

use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Support\Attributes\PackageUrlChange;
use App\Support\Attributes\PackageUrlChangeAttributes;
use App\Tests\Fixtures\Fixtures;
use PHPUnit\Framework\TestCase;

class PackageUrlChangeAttributesTest extends TestCase
{
    use Fixtures;

    public function testIsFullyAppliedOnceEveryTargetHasMoved(): void
    {
        $packages = [
            'acme/one' => self::createPackage('acme/one', 'https://github.com/moved/one'),
            'acme/two' => self::createPackage('acme/two', 'https://github.com/moved/two'),
        ];

        self::assertTrue($this->attributes()->isFullyApplied(static fn (string $name): ?Package => $packages[$name] ?? null));
    }

    public function testIsNotFullyAppliedWhileAnyTargetIsFrozen(): void
    {
        $frozen = self::createPackage('acme/two', 'https://github.com/moved/two');
        $frozen->freeze(PackageFreezeReason::RemoteIdMismatch);
        $packages = [
            'acme/one' => self::createPackage('acme/one', 'https://github.com/moved/one'),
            'acme/two' => $frozen,
        ];

        self::assertFalse($this->attributes()->isFullyApplied(static fn (string $name): ?Package => $packages[$name] ?? null));
    }

    public function testIsNotFullyAppliedWhileAnyTargetIsPendingOrGone(): void
    {
        $pending = [
            'acme/one' => self::createPackage('acme/one', 'https://github.com/moved/one'),
            'acme/two' => self::createPackage('acme/two', 'https://github.com/acme/two'),
        ];
        self::assertFalse($this->attributes()->isFullyApplied(static fn (string $name): ?Package => $pending[$name] ?? null));

        $gone = ['acme/one' => $pending['acme/one']];
        self::assertFalse($this->attributes()->isFullyApplied(static fn (string $name): ?Package => $gone[$name] ?? null));
    }

    public function testHostOfUnderstandsTheScpForm(): void
    {
        self::assertSame('git.example.org', PackageUrlChange::hostOf('git@git.example.org:acme/one.git'));
        self::assertSame('git.example.org', PackageUrlChange::hostOf('https://git.example.org/acme/one'));
        self::assertNull(PackageUrlChange::hostOf('not a url'));
    }

    private function attributes(): PackageUrlChangeAttributes
    {
        return new PackageUrlChangeAttributes([
            new PackageUrlChange('acme/one', 'https://github.com/moved/one'),
            new PackageUrlChange('acme/two', 'https://github.com/moved/two'),
        ]);
    }
}
