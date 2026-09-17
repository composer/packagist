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

use App\Search\PackageIndex;
use App\Search\Query;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Stands in for the real index and asserts the exact request body the app builds.
 *
 * That assertion is the point: search params passed to the client as request options instead are
 * silently discarded, so a mistake there would quietly drop filters and pagination rather than fail.
 */
final class AlgoliaMock implements PackageIndex
{
    private Query $query;

    /** @var array<string, mixed> */
    private array $result;

    public static function setup(KernelBrowser $client, Query $query, string $resultName): self
    {
        $mock = new self();
        $mock->query = $query;

        if (false === $result = @include __DIR__.'/results/'.$resultName.'.php') {
            throw new \InvalidArgumentException('Result set with name '.$resultName.' is not available.');
        }

        $mock->result = $result;

        $client->getContainer()->set(PackageIndex::class, $mock);

        return $mock;
    }

    public function search(array $searchParams): array
    {
        $expected = $this->query->getSearchParams();

        // assert the query on its own first, so an escaping regression is not buried in a whole-array diff
        $queryMessage = \sprintf('AlgoliaMock expected query string \'%s\', but got \'%s\'.', $expected['query'], $searchParams['query'] ?? '');
        Assert::assertSame($expected['query'], $searchParams['query'] ?? null, $queryMessage);
        Assert::assertSame($expected, $searchParams, 'AlgoliaMock expected different search params.');

        return $this->result;
    }

    public function browse(array $browseParams): iterable
    {
        Assert::fail('AlgoliaMock::browse() was not expected to be called.');
    }

    public function saveRecords(array $records): void
    {
        Assert::fail('AlgoliaMock::saveRecords() was not expected to be called.');
    }

    public function deleteRecord(string $objectId): void
    {
        Assert::fail('AlgoliaMock::deleteRecord() was not expected to be called.');
    }

    public function clear(): void
    {
        Assert::fail('AlgoliaMock::clear() was not expected to be called.');
    }

    public function updateSettings(array $settings): void
    {
        Assert::fail('AlgoliaMock::updateSettings() was not expected to be called.');
    }
}
