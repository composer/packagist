<?php declare(strict_types=1);

namespace App\Tests\Search;

use App\Search\Query;
use App\Search\ResultTransformer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\CompiledUrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

final class ResultTransformerTest extends TestCase
{
    /**
     * @dataProvider provideQueryWithResults
     */
    public function testTransform(Query $query, array $result, array $expectedResult): void
    {
        $transformer = new ResultTransformer(new UrlGeneratorMock());
        $actualResult = $transformer->transform($query, $result);

        static::assertSame($expectedResult, $actualResult);
    }

    public function provideQueryWithResults(): \Generator
    {
        yield 'simple-query' => [
            new Query('monolog', [], '', 15, 0),
            include __DIR__.'/results/search-with-query.php',
            include __DIR__.'/transformed/search-with-query.php',
        ];

        yield 'query-paged' => [
            new Query('pro', [], '', 3, 1),
            include __DIR__.'/results/search-paged.php',
            include __DIR__.'/transformed/search-paged.php',
        ];

        yield 'query-with-tag' => [
            new Query('pro', ['testing'], '', 15, 0),
            include __DIR__.'/results/search-with-query-tag.php',
            include __DIR__.'/transformed/search-with-query-tag.php',
        ];

        yield 'query-with-tags-type' => [
            new Query('pro', ['testing', 'mock'], 'library', 15, 0),
            include __DIR__.'/results/search-with-query-tags.php',
            include __DIR__.'/transformed/search-with-query-tags.php',
        ];

        yield 'abandoned' => [
            new Query('pro', [], '', 15, 0),
            include __DIR__.'/results/search-with-abandoned.php',
            include __DIR__.'/transformed/search-with-abandoned.php',
        ];

        yield 'virtual' => [
            new Query('pro', [], '', 15, 0),
            include __DIR__.'/results/search-with-virtual.php',
            include __DIR__.'/transformed/search-with-virtual.php',
        ];
    }
}
