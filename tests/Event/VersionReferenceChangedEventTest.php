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

namespace App\Tests\Event;

use App\Entity\Package;
use App\Entity\RequireLink;
use App\Entity\Version;
use App\Event\VersionReferenceChangedEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class VersionReferenceChangedEventTest extends TestCase
{
    #[TestWith([null, null, null, null, false], 'no references at all')]
    #[TestWith(['04dd4a93', 'b5c958d3', '04dd4a93', 'b5c958d3', false], 'both refs same')]
    #[TestWith(['04dd4a93', 'b5c958d3', '07d0ef27', 'b5c958d3', true], 'source changed, dist same')]
    #[TestWith(['04dd4a93', '07d0ef27', '04dd4a93', 'd19d25ea', true], 'source same, dist changed')]
    #[TestWith(['04dd4a93', 'b5c958d3', '07d0ef27', 'd19d25ea', true], 'both refs changed')]
    public function testHasReferenceChanged(
        ?string $oldSourceRef,
        ?string $oldDistRef,
        ?string $newSourceRef,
        ?string $newDistRef,
        bool $expected,
    ): void {
        $version = $this->createPackageAndVersion($oldSourceRef, $oldDistRef);
        $oldMetadata = $version->toV2Array([]);

        $version->setSource(array_merge($version->getSource(), ['reference' => $newSourceRef]));
        $version->setDist(array_merge($version->getDist(), ['reference' => $newDistRef]));

        $event = new VersionReferenceChangedEvent($version, $oldMetadata);

        $this->assertSame($expected, $event->hasReferenceChanged());
    }

    public function testHasMetadataChangedWithIdenticalMetadata(): void
    {
        $version = $this->createPackageAndVersion('old-ref', 'old-ref');
        $metadata = $version->toV2Array([]);

        $version->setSource(array_merge($version->getSource(), ['reference' => 'new-ref']));
        $version->setDist(array_merge($version->getDist(), ['reference' => 'new-ref']));

        $event = new VersionReferenceChangedEvent($version, $metadata);

        $this->assertFalse($event->hasMetadataChanged());
    }

    /**
     * @param string|array<string, mixed>|null $newValue
     */
    #[DataProvider('metadataProvider')]
    public function testHasMetadataChanged(string $key, string|array|null $newValue): void
    {
        $version = $this->createPackageAndVersion('old-ref', 'old-ref');
        $originalMetadata = $version->toV2Array([]);
        $originalMetadata[$key] = $newValue;

        $version->setSource(array_merge($version->getSource(), ['reference' => 'new-ref']));
        $version->setDist(array_merge($version->getDist(), ['reference' => 'new-ref']));

        $event = new VersionReferenceChangedEvent($version, array_filter($originalMetadata));

        $this->assertTrue($event->hasMetadataChanged());
    }

    public static function metadataProvider(): array
    {
        return [
            ['license', 'GPL-3.0-or-later'],
            ['require', ['composer/semver' => '^3.3.0']],
            ['autoload', ['psr-4' => ['App\\' => 'src/']]],
            ['license', null],
        ];
    }

    private function createPackageAndVersion(?string $sourceRef, ?string $distRef): Version
    {
        $start = microtime(true);
        $package = new Package();
        $package->setName('composer/composer');

        $version = new Version();
        $version->setPackage($package);
        $version->setName($package->getName());
        $version->setVersion('2.0.0');
        $version->setNormalizedVersion('2.0.0.0');
        $version->setDevelopment(false);
        $version->setLicense(['MIT']);
        $version->setAutoload([]);
        $version->setDist(['reference' => $distRef, 'type' => 'zip', 'url' => 'https://example.org/dist.zip']);
        $version->setSource(['reference' => $sourceRef, 'type' => 'git', 'url' => 'https://example.org/dist.git']);

        $link = new RequireLink();
        $link->setVersion($version);
        $link->setPackageVersion('^3.2.0');
        $link->setPackageName('composer/semver');
        $version->addRequireLink($link);

        $step1 = microtime(true);

        $reflectionClass = new \ReflectionClass($version);
        $reflectionProperty = $reflectionClass->getProperty('id');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($version, random_int(1, 9999));

        $end = microtime(true);

        $instantiation = $step1 - $start;
        $time = $end - $start - $instantiation;

        return $version;
    }
}
