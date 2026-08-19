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

use App\Audit\TransparencyLogType;
use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\PackageTransparencyLog;
use App\Log\Display\TransparencyLogDisplayFactory;
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
        'user' => ['id' => 1, 'username' => 'maintainer'],
        'actor' => ['id' => 2, 'username' => 'moderator'],
    ];

    /**
     * @return iterable<string, array{TransparencyLogType}>
     */
    public static function provideTypes(): iterable
    {
        foreach (TransparencyLogType::cases() as $type) {
            yield $type->value => [$type];
        }
    }

    #[DataProvider('provideTypes')]
    public function testEveryTypeBuildsADisplayRenderableByTheLogTable(TransparencyLogType $type): void
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

    private function entry(TransparencyLogType $type): PackageTransparencyLog
    {
        $package = new Package();
        $package->setName('acme/logged');
        new \ReflectionProperty($package, 'id')->setValue($package, 1);
        new \ReflectionProperty($package, 'repository')->setValue($package, 'https://github.com/acme/logged');

        return PackageTransparencyLog::project(
            AuditRecord::packageCreated($package, null),
            $type,
            1,
            self::ATTRIBUTES,
            null,
            'acme',
        );
    }
}
