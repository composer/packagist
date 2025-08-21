<?php

declare(strict_types=1);

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

use App\Entity\Tag;
use App\Entity\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    #[DataProvider('provideValidDevTagSets')]
    public function testHasDevTagWith(array $tags): void
    {
        $version = new Version();

        foreach ($tags as $tag) {
            $version->addTag(new Tag($tag));
        }

        self::assertTrue($version->hasDevTag());
    }

    public static function provideValidDevTagSets(): array
    {
        return [
            'only dev' => [['dev']],
            'dev first' => [['dev', 'database']],
            'dev last' => [['database', 'dev']],
            'dev middle' => [['orm', 'dev', 'database']],
            'multiple' => [['dev', 'testing']],
        ];
    }

    #[DataProvider('provideInvalidDevTagSets')]
    public function testHasDevTagWithout(array $tags): void
    {
        $version = new Version();

        foreach ($tags as $tag) {
            $version->addTag(new Tag($tag));
        }

        self::assertFalse($version->hasDevTag());
    }

    public static function provideInvalidDevTagSets(): array
    {
        return [
            'none' => [[]],
            'one' => [['orm']],
            'two' => [['database', 'orm']],
            'three' => [['currency', 'database', 'clock']],
        ];
    }
}
