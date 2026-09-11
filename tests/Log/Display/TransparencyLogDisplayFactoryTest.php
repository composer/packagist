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

namespace App\Tests\Log\Display;

use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\PackageTransparencyLog;
use App\Log\Display\Event\MaintainerAccountEventDisplay;
use App\Log\Display\Event\VersionCreatedDisplay;
use App\Log\Display\TransparencyLogDisplayFactory;
use App\Log\TransparencyLogEventType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TransparencyLogDisplayFactoryTest extends TestCase
{
    /**
     * Superset of every attribute the factory reads, so one fixture covers all types.
     */
    private const ATTRIBUTES = [
        'name' => 'acme/logged',
        'version' => '1.2.3',
        'repository' => 'https://github.com/acme/logged',
        'repository_from' => 'https://github.com/acme/old',
        'repository_to' => 'https://github.com/acme/logged',
        'replacement_package' => 'acme/replacement',
        'reason' => 'spam',
        'reasonText' => 'reported by three users',
        'previousReason' => 'spam',
        'ref_from' => 'aaaaaaa',
        'ref_to' => 'bbbbbbb',
        'previous_maintainers' => [['id' => 1, 'username' => 'before']],
        'current_maintainers' => [['id' => 2, 'username' => 'after']],
        'metadata' => [
            'source' => ['reference' => 'aaaaaaabbbbbbb'],
            'dist' => ['reference' => 'aaaaaaabbbbbbb', 'shasum' => 'ccccccc'],
        ],
        'user' => ['id' => 1, 'username' => 'maintainer'],
        'actor' => ['id' => 2, 'username' => 'moderator'],
    ];

    /**
     * @return iterable<string, array{TransparencyLogEventType}>
     */
    public static function provideTypes(): iterable
    {
        foreach (TransparencyLogEventType::cases() as $type) {
            yield $type->value => [$type];
        }
    }

    #[DataProvider('provideTypes')]
    public function testEveryTypeBuildsADisplayRenderableByTheLogTable(TransparencyLogEventType $type): void
    {
        $display = (new TransparencyLogDisplayFactory())->buildSingle($this->entry($type));

        self::assertSame($type, $display->getType());
        self::assertSame('log.type.'.$type->value, $display->getTypeTranslationKey());
        self::assertSame('moderator', $display->getActor()->username);
        self::assertFileExists(
            __DIR__.'/../../../templates/'.$display->getTemplateName(),
            $display->getTemplateName().' is missing',
        );
    }

    /**
     * @return iterable<string, array{TransparencyLogEventType}>
     */
    public static function provideFannedOutTypes(): iterable
    {
        foreach (TransparencyLogEventType::cases() as $type) {
            if ($type->fansOutToMaintainedPackages()) {
                yield $type->value => [$type];
            }
        }
    }

    /**
     * An account event carries no package of its own, so the row's own packageName column is the only
     * thing that tells the fanned-out copies apart on the unfiltered log.
     */
    #[DataProvider('provideFannedOutTypes')]
    public function testAccountEventNamesThePackageItWasFannedOutOnto(TransparencyLogEventType $type): void
    {
        // Deliberately different from the 'name' in self::ATTRIBUTES: the display must read the
        // column, not the source event's attributes.
        $display = new TransparencyLogDisplayFactory()->buildSingle($this->entry($type, 'acme/fanned-out'));

        self::assertInstanceOf(MaintainerAccountEventDisplay::class, $display);
        self::assertSame('acme/fanned-out', $display->packageName);
        self::assertSame('maintainer', $display->maintainerUsername);
    }

    /**
     * The published part of the version metadata blob is the reason a version_created leaf can be
     * checked against anything, so it has to reach the row rather than just sit in the column.
     */
    public function testVersionCreatedExposesThePublishedReferences(): void
    {
        $display = new TransparencyLogDisplayFactory()->buildSingle($this->entry(TransparencyLogEventType::VersionCreated));

        self::assertInstanceOf(VersionCreatedDisplay::class, $display);
        self::assertSame('1.2.3', $display->version);
        self::assertSame('aaaaaaabbbbbbb', $display->sourceReference);
        self::assertSame('aaaaaaabbbbbbb', $display->distReference);
        self::assertSame('ccccccc', $display->distShasum);
    }

    /**
     * Entries projected before the references were published carry no metadata at all, and a blob
     * can also be reduced to nothing (a driver reporting no usable reference), so the row has to
     * render without one.
     */
    public function testVersionCreatedWithoutMetadataStillBuilds(): void
    {
        $attributes = self::ATTRIBUTES;
        unset($attributes['metadata']);

        $display = new TransparencyLogDisplayFactory()->buildSingle($this->entry(TransparencyLogEventType::VersionCreated, attributes: $attributes));

        self::assertInstanceOf(VersionCreatedDisplay::class, $display);
        self::assertNull($display->sourceReference);
        self::assertNull($display->distReference);
        self::assertNull($display->distShasum);
    }

    /**
     * @param array<string, mixed>|null $attributes
     */
    private function entry(TransparencyLogEventType $type, string $packageName = 'acme/logged', ?array $attributes = null): PackageTransparencyLog
    {
        $package = new Package();
        $package->setName('acme/logged');
        new \ReflectionProperty($package, 'id')->setValue($package, 1);
        new \ReflectionProperty($package, 'repository')->setValue($package, 'https://github.com/acme/logged');

        return PackageTransparencyLog::project(
            AuditRecord::packageCreated($package, null),
            $type,
            1,
            $attributes ?? self::ATTRIBUTES,
            1,
            'acme',
            $packageName,
        );
    }
}
