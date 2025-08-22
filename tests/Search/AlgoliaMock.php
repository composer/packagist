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

use Algolia\AlgoliaSearch\SearchClient;
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
     * @override \Algolia\AlgoliaSearch\SearchClient::initIndex
     */
    public function initIndex($indexName): self
    {
        return $this;
    }

    /**
     * @override \Algolia\AlgoliaSearch\SearchIndex::search
     */
    public function search($query, $requestOptions = []): array
    {
        $queryMessage = \sprintf('AlgoliaMock expected query string \'%s\', but got \'%s\'.', $this->query->query, $query);
        Assert::assertSame($this->query->query, $query, $queryMessage);
        Assert::assertSame($this->query->getOptions(), $requestOptions, 'AlgoliaMock expected different request options.');

        return $this->result;
    }
}
