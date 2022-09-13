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

namespace App\Tests\Search;

use App\Search\Query;
use App\Search\ResultTransformer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ResultTransformerTest extends KernelTestCase
{
    /**
     * @dataProvider provideQueryWithResults
     */
    public function testTransform(Query $query, array $result, array $expectedResult): void
    {
        $transformer = static::getContainer()->get(ResultTransformer::class);
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
