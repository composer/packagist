<?php

namespace Packagist\WebBundle\Tests\Controller;

use Exception;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Model\DownloadManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

class WebControllerTest extends WebTestCase
{
    public function testHomepage()
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');
        $this->assertEquals('Getting Started', $crawler->filter('.getting-started h1')->text());
    }

    public function testPackages()
    {
        $client = self::createClient();
        //we expect at least one package
        $crawler = $client->request('GET', '/packages/');
        $this->assertTrue($crawler->filter('.packages li')->count() > 0);
    }

    public function testPackage()
    {
        $client = self::createClient();
        //we expect package to be clickable and showing at least 'package' div
        $crawler = $client->request('GET', '/packages/');
        $link = $crawler->filter('.packages li h1 a')->first()->attr('href');

        $crawler = $client->request('GET', $link);
        $this->assertTrue($crawler->filter('.package')->count() > 0);
    }

    /**
     * @covers ::nothing
     */
    public function testSearchNoOrderBysAction()
    {
        $json = $this->commonTestSearchActionOrderBysDownloads();

        $this->assertSame(
            $this->getJsonResults(
                array(
                    $this->getJsonResult('twig/twig', 25, 0),
                    $this->getJsonResult('composer/packagist', 12, 0),
                    $this->getJsonResult('symfony/symfony', 42, 0),
                )
            ),
            $json
        );
    }

    /**
     * @covers ::nothing
     */
    public function testSearchOrderByDownloadsAscAction()
    {
        $json = $this->commonTestSearchActionOrderBysDownloads(
            array(
                array(
                    'sort' => 'downloads',
                    'order' => 'asc',
                ),
            )
        );

        $this->assertSame(
            $this->getJsonResults(
                array(
                    $this->getJsonResult('composer/packagist', 12, 0),
                    $this->getJsonResult('twig/twig', 25, 0),
                    $this->getJsonResult('symfony/symfony', 42, 0),
                )
            ),
            $json
        );
    }

    /**
     * @covers ::nothing
     */
    public function testSearchOrderByDownloadsDescAction()
    {
        $json = $this->commonTestSearchActionOrderBysDownloads(
            array(
                array(
                    'sort' => 'downloads',
                    'order' => 'desc',
                ),
            )
        );

        $this->assertSame(
            $this->getJsonResults(
                array(
                    $this->getJsonResult('symfony/symfony', 42, 0),
                    $this->getJsonResult('twig/twig', 25, 0),
                    $this->getJsonResult('composer/packagist', 12, 0),
                )
            ),
            $json
        );
    }

    /**
     * @param callable $onBeforeIndex TODO Add typehint when migrating to 5.4+
     * @param array $orderBys
     *
     * @return array
     */
    protected function commonTestSearchActionOrderBysAction(
        $onBeforeIndex,
        array $orderBys = array()
    ) {
        $client = self::createClient();

        $container = $client->getContainer();

        $kernelRootDir = $container->getParameter('kernel.root_dir');

        $this->executeCommand($kernelRootDir . '/console doctrine:database:drop --env=test --force');
        $this->executeCommand($kernelRootDir . '/console doctrine:database:create --env=test');
        $this->executeCommand($kernelRootDir . '/console doctrine:schema:create --env=test');
        $this->executeCommand($kernelRootDir . '/console redis:flushall --env=test -n');

        $lock = $container->getParameter('kernel.cache_dir').'/composer-indexer.lock';

        $this->executeCommand('rm -f ' . $lock);

        $em = $container->get('doctrine')->getManager();

        if (!empty($orderBys)) {
            $orderBysQryStrPart = '&' . http_build_query(
                array(
                    'orderBys' => $orderBys
                )
            );
        } else {
            $orderBysQryStrPart = '';
        }

        $client->request('GET', '/search.json?q=' . $orderBysQryStrPart);

        $response = $client->getResponse();

        $content = $client->getResponse()->getContent();

        $this->assertSame(200, $response->getStatusCode(), $content);

        $package = new Package();

        $package->setName('twig/twig');
        $package->setRepository('https://github.com/twig/twig');

        $package1 = new Package();

        $package1->setName('composer/packagist');
        $package1->setRepository('https://github.com/composer/packagist');

        $package2 = new Package();

        $package2->setName('symfony/symfony');
        $package2->setRepository('https://github.com/symfony/symfony');

        $em->persist($package);
        $em->persist($package1);
        $em->persist($package2);

        $em->flush();

        $onBeforeIndex($container, $package, $package1, $package2);

        $this->executeCommand($kernelRootDir . '/console packagist:index --env=test --force');

        return json_decode($content, true);
    }

    /**
     * @param array $orderBys
     *
     * @return array
     */
    protected function commonTestSearchActionOrderBysDownloads(
        array $orderBys = array()
    ) {
        return $this->commonTestSearchActionOrderBysAction(
            function (
                ContainerInterface $container,
                Package $package,
                Package $package1,
                Package $package2
            ) {
                $downloadManager = $container->get('packagist.download_manager');

                /* @var $downloadManager DownloadManager */

                for ($i = 0; $i < 25; $i += 1) {
                    $downloadManager->addDownload($package->getId(), 25);
                }
                for ($i = 0; $i < 12; $i += 1) {
                    $downloadManager->addDownload($package1->getId(), 12);
                }
                for ($i = 0; $i < 42; $i += 1) {
                    $downloadManager->addDownload($package2->getId(), 42);
                }
            },
            $orderBys
        );
    }

    /**
     * Executes a given command.
     *
     * @param string $command a command to execute
     *
     * @throws Exception when the return code is not 0.
     */
    protected function executeCommand(
        $command
    ) {
        $output = array();

        $returnCode = null;;

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
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