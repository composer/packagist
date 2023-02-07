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

use App\Search\Query;
use App\Tests\Search\AlgoliaMock;
use Doctrine\DBAL\Connection;
use Exception;
use App\Entity\Package;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

class WebControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    public function setUp(): void
    {
        $this->client = self::createClient();
        static::getContainer()->get(Connection::class)->beginTransaction();

        parent::setUp();
    }

    public function tearDown(): void
    {
        static::getContainer()->get(Connection::class)->rollBack();

        parent::tearDown();
    }

    public function testHomepage(): void
    {
        $crawler = $this->client->request('GET', '/');
        $this->assertEquals('Getting Started', $crawler->filter('.getting-started h2')->text());
    }

    public function testRedirectsOnMatch(): void
    {
        $this->initializePackages($this->client->getContainer());

        $this->client->request('GET', '/', ['query' => 'twig/twig']);
        static::assertResponseRedirects('/packages/twig/twig', 302);
    }

    public function testHomepageDoesntRedirectsOnNoMatch(): void
    {
        $crawler = $this->client->request('GET', '/', ['q' => 'symfony/process']);
        static::assertResponseIsSuccessful();
        static::assertEquals('symfony/process', $crawler->filter('input[type=search]')->attr('value'));
    }

    public function testSearchRedirectsOnMatch(): void
    {
        $this->initializePackages($this->client->getContainer());

        $this->client->request('GET', '/search/', ['query' => 'twig/twig']);
        static::assertResponseRedirects('/packages/twig/twig', 302);
    }

    public function testSearchRendersEmptyOnHtml(): void
    {
        $crawler = $this->client->request('GET', '/search/');
        static::assertResponseIsSuccessful();
        static::assertEquals('Search by', $crawler->filter('.content')->text());
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
        static::assertJsonStringEqualsJsonFile(__DIR__ . '/responses/search-with-query.json', $this->client->getResponse()->getContent());
    }

    public function testSearchJsonWithQueryAndTag(): void
    {
        AlgoliaMock::setup($this->client, new Query('pro', ['testing'], '', 15, 0), 'search-with-query-tag');

        $this->client->request('GET', '/search.json', ['q' => 'pro', 'tags' => 'testing']);
        static::assertResponseStatusCodeSame(200);
        static::assertJsonStringEqualsJsonFile(__DIR__ . '/responses/search-with-query-tag.json', $this->client->getResponse()->getContent());
    }

    public function testSearchJsonWithQueryAndTagsAndTypes(): void
    {
        AlgoliaMock::setup($this->client, new Query('pro', ['testing', 'mock'], 'library', 15, 0), 'search-with-query-tags');

        $this->client->request('GET', '/search.json', ['q' => 'pro', 'tags' => ['testing', 'mock'], 'type' => 'library']);
        static::assertResponseStatusCodeSame(200);
        static::assertJsonStringEqualsJsonFile(__DIR__ . '/responses/search-with-query-tags.json', $this->client->getResponse()->getContent());
    }

    public function testPackages(): void
    {
        $this->initializePackages($this->client->getContainer());

        //we expect at least one package
        $crawler = $this->client->request('GET', '/explore/');
        $this->assertGreaterThan(0, $crawler->filter('.packages-short li')->count());
    }

    public function testPackage(): void
    {
        $this->initializePackages($this->client->getContainer());

        //we expect package to be clickable and showing at least 'package' div
        $crawler = $this->client->request('GET', '/packages/symfony/symfony');
        $this->assertGreaterThan(0, $crawler->filter('.package')->count());
    }

    /**
     * @return Package[]
     */
    protected function initializePackages(ContainerInterface $container): array
    {
        $em = $container->get('doctrine')->getManager();

        $twigPackage = new Package();

        $twigPackage->setName('twig/twig');
        $twigPackage->setRepository('https://github.com/twig/twig');

        $packagistPackage = new Package();

        $packagistPackage->setName('composer/packagist');
        $packagistPackage->setRepository('https://github.com/composer/packagist');

        $symfonyPackage = new Package();

        $symfonyPackage->setName('symfony/symfony');
        $symfonyPackage->setRepository('https://github.com/symfony/symfony');

        $em->persist($twigPackage);
        $em->persist($packagistPackage);
        $em->persist($symfonyPackage);

        $em->flush();

        return [$twigPackage, $packagistPackage, $symfonyPackage];
    }
}
