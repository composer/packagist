<?php declare(strict_types=1);

namespace App\Tests\Search;

use App\Search\Query;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type SearchOptions from \App\Search\Query
 */
final class QueryTest extends TestCase
{
    public function testConstructWithoutQueryTypeTags(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing search query, example: ?q=example');

        new Query('', [], '', 15, 1);
    }

    public function testConstructWithoutQueryButTags(): void
    {
        $query = new Query('', ['testing'], '', 15, 1);

        static::assertEmpty($query->query);
        static::assertSame(['testing'], $query->tags);
        static::assertEmpty($query->type);
    }

    public function testConstructWithoutQueryButType(): void
    {
        $query = new Query('', [], 'symfony-bundle', 15, 1);

        static::assertEmpty($query->query);
        static::assertEmpty($query->tags);
        static::assertSame('symfony-bundle', $query->type);
    }

    public function testConstructWithQuery(): void
    {
        $query = new Query('monolog', [], '', 15, 1);

        static::assertSame('monolog', $query->query);
        static::assertEmpty($query->tags);
        static::assertEmpty($query->type);
    }

    /**
     * @dataProvider provideQueryForEscaping
     */
    public function testConstructQueryEscaping(string $query, string $expected): void
    {
        $query = new Query($query, [], '', 15, 1);

        static::assertSame($expected, $query->query);
    }

    /**
     * @return array<array{0: string, 1: string}>
     */
    public function provideQueryForEscaping(): array
    {
        return [
            ['symfony/property', 'symfony/property'],
            ['symfony/property -info', 'symfony/property -info'],
            ['symfony/property-info', 'symfony/property--info'],
            ['symfony/property-info-info', 'symfony/property--info--info'],
            ['symfony/property-info-info -info', 'symfony/property--info--info -info'],
        ];
    }

    public function testConstructReplaceOnType(): void
    {
        $query = new Query('', [], 'idont%type%know', 15, 1);

        static::assertSame('idontknow', $query->type);
    }

    public function testConstructPerPage(): void
    {
        $query = new Query('monolog', [], '', 15, 1);

        static::assertSame(15, $query->perPage);
    }

    public function testConstructPerPageNotZero(): void
    {
        $query = new Query('monolog', [], '', 0, 1);

        static::assertSame(1, $query->perPage);
    }

    public function testConstructPerPageNotNegative(): void
    {
        $query = new Query('monolog', [], '', -10, 1);

        static::assertSame(1, $query->perPage);
    }

    public function testConstructPerPageNotLargerThan100(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The optional packages per_page parameter must be an integer between 1 and 100 (default: 15)');

        new Query('monolog', [], '', 115, 1);
    }

    public function testConstructPageSubtracted(): void
    {
        $query = new Query('monolog', [], '', 15, 1);

        static::assertSame(0, $query->page);
    }

    public function testConstructPageNotZero(): void
    {
        $query = new Query('monolog', [], '', 15, 0);

        static::assertSame(0, $query->page);
    }

    public function testConstructPageNotNegative(): void
    {
        $query = new Query('monolog', [], '', 15, -10);

        static::assertSame(0, $query->page);
    }

    /**
     * @dataProvider provideQueryWithOptions
     *
     * @phpstan-param SearchOptions $expectedOptions
     */
    public function testGetOptions(Query $query, array $expectedOptions): void
    {
        static::assertSame($expectedOptions, $query->getOptions());
    }

    /**
     * @phpstan-return iterable<string, array{0: Query, 1: SearchOptions}>
     */
    public function provideQueryWithOptions(): iterable
    {
        yield 'empty_tag_type' => [
            new Query('monolog', [], '', 15, 1),
            ['hitsPerPage' => 15, 'page' => 0]
        ];

        yield 'with_single_tag' => [
            new Query('monolog', ['testing'], '', 15, 1),
            ['hitsPerPage' => 15, 'page' => 0, 'filters' => '(tags:"testing")']
        ];

        yield 'with_single_tag_but_space' => [
            new Query('monolog', ['testing mock'], '', 15, 1),
            ['hitsPerPage' => 15, 'page' => 0, 'filters' => '(tags:"testing mock" OR tags:"testing-mock")']
        ];

        yield 'with_multiple_tags' => [
            new Query('monolog', ['testing', 'mock'], '', 15, 1),
            ['hitsPerPage' => 15, 'page' => 0, 'filters' => '(tags:"testing" OR tags:"mock")']
        ];

        yield 'with_type' => [
            new Query('monolog', [], 'symfony-bundle', 15, 1),
            ['hitsPerPage' => 15, 'page' => 0, 'filters' => 'type:symfony-bundle']
        ];

        yield 'with_single_tag_and_type' => [
            new Query('monolog', ['testing'], 'symfony-bundle', 15, 1),
            ['hitsPerPage' => 15, 'page' => 0, 'filters' => 'type:symfony-bundle AND (tags:"testing")']
        ];

        yield 'with_multiple_tags_and_type' => [
            new Query('monolog', ['testing', 'mock'], 'symfony-bundle', 15, 1),
            ['hitsPerPage' => 15, 'page' => 0, 'filters' => 'type:symfony-bundle AND (tags:"testing" OR tags:"mock")']
        ];
    }
}
