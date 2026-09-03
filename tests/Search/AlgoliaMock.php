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

use Algolia\AlgoliaSearch\Api\SearchClient;
use App\Search\Query;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AlgoliaMock extends SearchClient
{
    private Query $query;
    private array $result;

    public static function setup(KernelBrowser $client, Query $query, string $resultName): self
    {
        $mock = new \ReflectionClass(__CLASS__)->newInstanceWithoutConstructor();
        $mock->query = $query;

        if (false === $result = @include __DIR__.'/results/'.$resultName.'.php') {
            throw new \InvalidArgumentException('Result set with name '.$resultName.' is not available.');
        }

        $mock->result = $result;

        $client->getContainer()->set(SearchClient::class, $mock);

        return $mock;
    }

    /**
     * @override \Algolia\AlgoliaSearch\Api\SearchClient::searchSingleIndex
     */
    public function searchSingleIndex($indexName, $searchParams = null, $requestOptions = []): array
    {
        $expected = ['query' => $this->query->query] + $this->query->getOptions();
        Assert::assertSame($expected, $searchParams, 'AlgoliaMock expected different search parameters.');

        return $this->result;
    }
}
