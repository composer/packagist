<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Tag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TagTest extends TestCase
{
    #[DataProvider('provideValidNames')]
    public function testIsDevWithValidNames(string $name): void
    {
        $tag = new Tag($name);

        self::assertTrue($tag->isDev());
    }

    public static function provideValidNames(): array
    {
        return [
            ['dev'],
            ['testing'],
            ['static analysis'],
        ];
    }

    #[DataProvider('provideInvalidNames')]
    public function testIsDevWithInvalidNames(string $name): void
    {
        $tag = new Tag($name);

        self::assertFalse($tag->isDev());
    }

    public static function provideInvalidNames(): array
    {
        return [
            ['orm'],
            ['project'],
            ['static-analysis'],
        ];
    }
}
