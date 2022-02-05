<?php

namespace App\Tests\Controller;

use Algolia\AlgoliaSearch\SearchClient;
use Algolia\AlgoliaSearch\SearchIndex;
use Exception;
use App\Entity\Package;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

class WebControllerTest extends WebTestCase
{
    public function testHomepage(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');
        $this->assertEquals('Getting Started', $crawler->filter('.getting-started h2')->text());
    }

    public function testRedirectsOnMatch(): void
    {
        $client = self::createClient();

        $this->initializePackages($client->getContainer());

        $client->request('GET', '/', ['query' => 'twig/twig']);
        static::assertResponseRedirects('/packages/twig/twig', 302);
    }

    public function testHomepageDoesntRedirectsOnNoMatch(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/', ['q' => 'symfony/process']);
        static::assertResponseIsSuccessful();
        static::assertEquals('symfony/process', $crawler->filter('input[type=search]')->attr('value'));
    }

    public function testSearchRedirectsOnMatch(): void
    {
        $client = self::createClient();

        $this->initializePackages($client->getContainer());

        $client->request('GET', '/search/', ['query' => 'twig/twig']);
        static::assertResponseRedirects('/packages/twig/twig', 302);
    }

    public function testSearchRendersEmptyOnHtml(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/search/');
        static::assertResponseIsSuccessful();
        static::assertEquals('Search by', $crawler->filter('.content')->text());
    }

    public function testSearchJsonWithoutQuery(): void
    {
        $client = self::createClient();

        $client->request('GET', '/search.json');
        static::assertResponseStatusCodeSame(400);
        static::assertStringContainsString('Missing search query', $client->getResponse()->getContent());
    }

    public function testSearchJsonWithQuery(): void
    {
        $client = self::createClient();

        $this->setupSearchFixture(
            $client->getContainer(),
            'monolog',
            ['hitsPerPage' => 15, 'page' => 0],
            __DIR__.'/fixtures/search-with-query.php',
        );

        $client->request('GET', '/search.json', ['q' => 'monolog']);
        static::assertResponseStatusCodeSame(200);
        static::assertJsonStringEqualsJsonFile(__DIR__.'/fixtures/search-with-query.json', $client->getResponse()->getContent());
    }

    public function testSearchJsonWithQueryAndTag(): void
    {
        $client = self::createClient();

        $this->setupSearchFixture(
            $client->getContainer(),
            'pro',
            ['hitsPerPage' => 15, 'page' => 0, 'filters' => '(tags:"testing")'],
            __DIR__.'/fixtures/search-with-query-tag.php',
        );

        $client->request('GET', '/search.json', ['q' => 'pro', 'tags' => 'testing']);
        static::assertResponseStatusCodeSame(200);
        static::assertJsonStringEqualsJsonFile(__DIR__.'/fixtures/search-with-query-tag.json', $client->getResponse()->getContent());
    }

    public function testSearchJsonWithQueryAndTagsAndTypes(): void
    {
        $client = self::createClient();

        $this->setupSearchFixture(
            $client->getContainer(),
            'pro',
            ['hitsPerPage' => 15, 'page' => 0, 'filters' => 'type:library AND (tags:"testing" OR tags:"mock")'],
            __DIR__.'/fixtures/search-with-query-tags.php',
        );

        $client->request('GET', '/search.json', ['q' => 'pro', 'tags' => ['testing', 'mock'], 'type' => 'library']);
        static::assertResponseStatusCodeSame(200);
        static::assertJsonStringEqualsJsonFile(__DIR__.'/fixtures/search-with-query-tags.json', $client->getResponse()->getContent());
    }

    public function testPackages()
    {
        $client = self::createClient();

        $this->initializePackages($client->getContainer());

        //we expect at least one package
        $crawler = $client->request('GET', '/explore/');
        $this->assertGreaterThan(0, $crawler->filter('.packages-short li')->count());
    }

    public function testPackage()
    {
        $client = self::createClient();

        $this->initializePackages($client->getContainer());

        //we expect package to be clickable and showing at least 'package' div
        $crawler = $client->request('GET', '/explore/');
        $link = $crawler->filter('.packages-short li a')->first()->attr('href');

        $crawler = $client->request('GET', $link);
        $this->assertGreaterThan(0, $crawler->filter('.package')->count());
    }

    protected function initializePackages(ContainerInterface $container)
    {
        $projectDir = $container->getParameter('kernel.project_dir');

        $this->executeCommand('php '.$projectDir . '/bin/console doctrine:database:drop --env=test --force -q', false);
        $this->executeCommand('php '.$projectDir . '/bin/console doctrine:database:create --env=test -q');
        $this->executeCommand('php '.$projectDir . '/bin/console doctrine:schema:create --env=test -q');
        $this->executeCommand('php '.$projectDir . '/bin/console redis:flushall --env=test -n -q');

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

    /**
     * Executes a given command.
     *
     * @param string $command a command to execute
     * @param bool $errorHandling
     *
     * @throws Exception when the return code is not 0.
     */
    protected function executeCommand(
        $command,
        $errorHandling = true
    ) {
        $output = array();

        $returnCode = null;;

        exec($command, $output, $returnCode);

        if ($errorHandling && $returnCode !== 0) {
            throw new Exception(
                sprintf(
                    'Error executing command "%s", return code was "%s".',
                    $command,
                    $returnCode
                )
            );
        }
    }

    private function setupSearchFixture(
        ContainerInterface $container,
        string $query,
        array $queryParams,
        string $resultFile,
    ): void {
        $indexMock = $this->createMock(SearchIndex::class);
        $indexMock
            ->method('search')
            ->with($query, $queryParams)
            ->willReturn(include $resultFile);

        $searchMock = $this->createMock(SearchClient::class);
        $searchMock
            ->method('initIndex')
            ->willReturn($indexMock);

        $container->set(SearchClient::class, $searchMock);
    }
}
