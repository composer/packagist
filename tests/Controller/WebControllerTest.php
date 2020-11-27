<?php

namespace App\Tests\Controller;

use Exception;
use App\Entity\Package;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

class WebControllerTest extends WebTestCase
{
    public function testHomepage()
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');
        $this->assertEquals('Getting Started', $crawler->filter('.getting-started h2')->text());
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

    /**
     * @param string $package
     * @param int $downloads
     * @param int $favers
     *
     * @return array
     */
    protected function getJsonResult($package, $downloads, $favers)
    {
        return array(
            'name' => $package,
            'description' => '',
            'url' => 'http://localhost/packages/' . $package,
            'repository' => 'https://github.com/' . $package,
            'downloads' => $downloads,
            'favers' => $favers,
        );
    }

    /**
     * @param array $results
     *
     * @return array
     */
    protected function getJsonResults(
        array $results
    ) {
        return array(
            'results' => $results,
            'total' => count($results)
        );
    }
}
