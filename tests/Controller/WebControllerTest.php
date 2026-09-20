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

namespace App\Tests\Controller;

use App\Entity\Package;
use App\Search\Query;
use App\Tests\IntegrationTestCase;
use App\Tests\Search\AlgoliaMock;
use Predis\Client as RedisClient;
use Predis\ClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class WebControllerTest extends IntegrationTestCase
{
    public function testHomepage(): void
    {
        $crawler = $this->client->request('GET', '/');
        static::assertResponseIsSuccessful();
        $this->assertStringContainsString('Packagist aggregates public packages', $crawler->filter('.hero-search-claim')->text());

        // while the hero is visible it owns the page heading, so the navbar brand must not be one
        static::assertCount(1, $crawler->filter('h1'));
        static::assertSame('Packagist', $crawler->filter('h1.hero-brand-title')->text());
        static::assertCount(3, $crawler->filter('.hero-search-stats li'));

        // the content block must render whether or not the blog feed returned anything
        static::assertSame('Installing PHP Dependencies', $crawler->filter('#getting-started')->text());
        static::assertSame('Publishing Packages', $crawler->filter('#how-to-submit-packages')->text());
    }

    public function testHomepageRendersContentWithoutNews(): void
    {
        self::getContainer()->set('http_client', new MockHttpClient(new MockResponse('nope', ['http_code' => 500])));
        self::getContainer()->get('cache.app')->delete('blog_news_items');

        $crawler = $this->client->request('GET', '/');
        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('.content h2:contains("Latest News")'));
        static::assertSame('Installing PHP Dependencies', $crawler->filter('#getting-started')->text());
        static::assertSame('Publishing Packages', $crawler->filter('#how-to-submit-packages')->text());
    }

    public function testHomepageDegradesWhenRedisIsDown(): void
    {
        self::getContainer()->set('snc_redis.default', $this->createBrokenRedis());

        $crawler = $this->client->request('GET', '/');
        static::assertResponseIsSuccessful();
        static::assertStringContainsString('N/A', $crawler->filter('.hero-search-stats')->text());
    }

    public function testStatsTotalsJson(): void
    {
        $this->client->request('GET', '/statistics.json');
        static::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        static::assertIsArray($data);
        static::assertSame(['downloads', 'packages', 'versions'], array_keys($data['totals']));
    }

    public function testStatsTotalsJsonIsUnavailableWhenRedisIsDown(): void
    {
        self::getContainer()->set('snc_redis.default', $this->createBrokenRedis());

        $this->client->request('GET', '/statistics.json');
        static::assertResponseStatusCodeSame(502);
    }

    private function createBrokenRedis(): RedisClient
    {
        return new class () extends RedisClient {
            public function __call($commandID, $arguments): mixed
            {
                throw new ClientException('redis is down');
            }
        };
    }

    public function testRedirectsOnMatch(): void
    {
        $this->initializePackages();

        $this->client->request('GET', '/', ['query' => 'twig/twig']);
        static::assertResponseRedirects('/packages/twig/twig', 302);
    }

    public function testHomepageDoesntRedirectsOnNoMatch(): void
    {
        $crawler = $this->client->request('GET', '/', ['q' => 'symfony/process']);
        static::assertResponseIsSuccessful();
        static::assertEquals('symfony/process', $crawler->filter('input[type=search]')->attr('value'));

        // the hero is collapsed server-side, which hands the page heading back to the navbar
        static::assertCount(1, $crawler->filter('.wrapper-search-hero.search-active'));
        static::assertCount(1, $crawler->filter('h1'));
        static::assertCount(1, $crawler->filter('h1.navbar-brand'));
    }

    public function testSearchRedirectsOnMatch(): void
    {
        $this->initializePackages();

        $this->client->request('GET', '/search/', ['query' => 'twig/twig']);
        static::assertResponseRedirects('/packages/twig/twig', 302);
    }

    public function testSearchRendersEmptyOnHtml(): void
    {
        $crawler = $this->client->request('GET', '/search/');
        static::assertResponseIsSuccessful();
        static::assertEquals('Active filters Search by', $crawler->filter('.content')->text());
    }

    public function testSearchJsonWithoutQuery(): void
    {
        $this->client->request('GET', '/search.json');
        static::assertResponseStatusCodeSame(400);
        static::assertStringContainsString('Missing search query', $this->client->getResponse()->getContent());
    }

    public function testSearchJsonWithQuery(): void
    {
        AlgoliaMock::setup($this->client, new Query('monolog', [], '', 15, 0), 'search-with-query');

        $this->client->request('GET', '/search.json', ['q' => 'monolog']);
        static::assertResponseStatusCodeSame(200);
        static::assertJsonStringEqualsJsonFile(__DIR__.'/responses/search-with-query.json', $this->client->getResponse()->getContent());
    }

    public function testSearchJsonWithQueryAndTag(): void
    {
        AlgoliaMock::setup($this->client, new Query('pro', ['testing'], '', 15, 0), 'search-with-query-tag');

        $this->client->request('GET', '/search.json', ['q' => 'pro', 'tags' => 'testing']);
        static::assertResponseStatusCodeSame(200);
        static::assertJsonStringEqualsJsonFile(__DIR__.'/responses/search-with-query-tag.json', $this->client->getResponse()->getContent());
    }

    public function testSearchJsonWithQueryAndTagsAndTypes(): void
    {
        AlgoliaMock::setup($this->client, new Query('pro', ['testing', 'mock'], 'library', 15, 0), 'search-with-query-tags');

        $this->client->request('GET', '/search.json', ['q' => 'pro', 'tags' => ['testing', 'mock'], 'type' => 'library']);
        static::assertResponseStatusCodeSame(200);
        static::assertJsonStringEqualsJsonFile(__DIR__.'/responses/search-with-query-tags.json', $this->client->getResponse()->getContent());
    }

    public function testPackages(): void
    {
        $this->initializePackages();

        // we expect at least one package
        $crawler = $this->client->request('GET', '/explore/');
        $this->assertGreaterThan(0, $crawler->filter('.packages-short li')->count());
    }

    public function testPackage(): void
    {
        $this->initializePackages();

        // we expect package to be clickable and showing at least 'package' div
        $crawler = $this->client->request('GET', '/packages/symfony/symfony');
        $this->assertGreaterThan(0, $crawler->filter('.package')->count());
    }

    /**
     * @return Package[]
     */
    protected function initializePackages(): array
    {
        $em = self::getEM();

        $twigPackage = $this->createPackage('twig/twig', 'https://github.com/twigphp/Twig', 'github.com/330275');
        $packagistPackage = $this->createPackage('composer/packagist', 'https://github.com/composer/packagist');
        $symfonyPackage = $this->createPackage('symfony/symfony', 'https://github.com/symfony/symfony', 'github.com/458058');

        $em->persist($twigPackage);
        $em->persist($packagistPackage);
        $em->persist($symfonyPackage);

        $em->flush();

        return [$twigPackage, $packagistPackage, $symfonyPackage];
    }
}
