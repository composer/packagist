<?php

namespace Packagist\WebBundle\Tests\Controller;

use Exception;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Model\DownloadManager;
use Packagist\WebBundle\Model\FavoriteManager;
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
        $crawler = $client->request('GET', '/packages/');
        $this->assertTrue($crawler->filter('.packages li')->count() > 0);
    }

    public function testPackage()
    {
        $client = self::createClient();

        $this->initializePackages($client->getContainer());

        //we expect package to be clickable and showing at least 'package' div
        $crawler = $client->request('GET', '/packages/');
        $link = $crawler->filter('.packages li a')->first()->attr('href');

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
     * @covers ::nothing
     */
    public function testSearchOrderByFaversAscAction()
    {
        $json = $this->commonTestSearchActionOrderBysFavers(
            array(
                array(
                    'sort' => 'favers',
                    'order' => 'asc',
                ),
            )
        );

        $this->assertSame(
            $this->getJsonResults(
                array(
                    $this->getJsonResult('composer/packagist', 0, 1),
                    $this->getJsonResult('twig/twig', 0, 2),
                    $this->getJsonResult('symfony/symfony', 0, 3),
                )
            ),
            $json
        );
    }

    /**
     * @covers ::nothing
     */
    public function testSearchOrderByFaversDescAction()
    {
        $json = $this->commonTestSearchActionOrderBysFavers(
            array(
                array(
                    'sort' => 'favers',
                    'order' => 'desc',
                ),
            )
        );

        $this->assertSame(
            $this->getJsonResults(
                array(
                    $this->getJsonResult('symfony/symfony', 0, 3),
                    $this->getJsonResult('twig/twig', 0, 2),
                    $this->getJsonResult('composer/packagist', 0, 1),
                )
            ),
            $json
        );
    }

    /**
     * @covers ::nothing
     */
    public function testSearchOrderBysCombinationAction()
    {
        $userMock = $this->getMock('Packagist\WebBundle\Entity\User');
        $userMock1 = $this->getMock('Packagist\WebBundle\Entity\User');
        $userMock2 = $this->getMock('Packagist\WebBundle\Entity\User');

        $userMock->method('getId')->will($this->returnValue(1));
        $userMock1->method('getId')->will($this->returnValue(2));
        $userMock2->method('getId')->will($this->returnValue(3));

        $json = $this->commonTestSearchActionOrderBysAction(
            function (
                ContainerInterface $container,
                Package $twigPackage,
                Package $packagistPackage,
                Package $symfonyPackage
            ) use (
                $userMock,
                $userMock1,
                $userMock2
            ) {
                $downloadManager = $container->get('packagist.download_manager');

                /* @var $downloadManager DownloadManager */

                for ($i = 0; $i < 25; $i += 1) {
                    $downloadManager->addDownload($twigPackage->getId(), 25);
                }
                for ($i = 0; $i < 12; $i += 1) {
                    $downloadManager->addDownload($packagistPackage->getId(), 12);
                }
                for ($i = 0; $i < 25; $i += 1) {
                    $downloadManager->addDownload($symfonyPackage->getId(), 42);
                }

                $favoriteManager = $container->get('packagist.favorite_manager');

                /* @var $favoriteManager FavoriteManager */

                $favoriteManager->markFavorite($userMock, $packagistPackage);

                $favoriteManager->markFavorite($userMock, $symfonyPackage);
                $favoriteManager->markFavorite($userMock1, $symfonyPackage);
                $favoriteManager->markFavorite($userMock2, $symfonyPackage);
            },
            array(
                array(
                    'sort' => 'downloads',
                    'order' => 'desc',
                ),
                array(
                    'sort' => 'favers',
                    'order' => 'desc',
                ),
            )
        );

        $this->assertSame(
            $this->getJsonResults(
                array(
                    $this->getJsonResult('symfony/symfony', 25, 3),
                    $this->getJsonResult('twig/twig', 25, 0),
                    $this->getJsonResult('composer/packagist', 12, 1),
                )
            ),
            $json
        );
    }

    /**
     * @param callable $onBeforeIndex
     * @param array $orderBys
     *
     * @return array
     */
    protected function commonTestSearchActionOrderBysAction(
        callable $onBeforeIndex,
        array $orderBys = array()
    ) {
        $client = self::createClient();

        $container = $client->getContainer();

        $kernelRootDir = $container->getParameter('kernel.root_dir');

        $this->executeCommand('php '.$kernelRootDir . '/console doctrine:database:drop --env=test --force', false);
        $this->executeCommand('php '.$kernelRootDir . '/console doctrine:database:create --env=test');
        $this->executeCommand('php '.$kernelRootDir . '/console doctrine:schema:create --env=test');
        $this->executeCommand('php '.$kernelRootDir . '/console redis:flushall --env=test -n');

        $lock = $container->getParameter('kernel.cache_dir').'/composer-indexer.lock';

        $this->executeCommand('rm -f ' . $lock);

        list($twigPackage, $packagistPackage, $symfonyPackage) = $this->initializePackages($container);

        if (!empty($orderBys)) {
            $orderBysQryStrPart = '&' . http_build_query(
                array(
                    'orderBys' => $orderBys
                )
            );
        } else {
            $orderBysQryStrPart = '';
        }

        $onBeforeIndex($container, $twigPackage, $packagistPackage, $symfonyPackage);

        $this->executeCommand('php '.$kernelRootDir . '/console packagist:index --env=test --force');

        $client->request('GET', '/search.json?q=' . $orderBysQryStrPart);

        $response = $client->getResponse();

        $content = $client->getResponse()->getContent();

        $this->assertSame(200, $response->getStatusCode(), $content);

        return json_decode($content, true);
    }

    protected function initializePackages(ContainerInterface $container)
    {
        $kernelRootDir = $container->getParameter('kernel.root_dir');

        $this->executeCommand('php '.$kernelRootDir . '/console doctrine:database:drop --env=test --force', false);
        $this->executeCommand('php '.$kernelRootDir . '/console doctrine:database:create --env=test');
        $this->executeCommand('php '.$kernelRootDir . '/console doctrine:schema:create --env=test');
        $this->executeCommand('php '.$kernelRootDir . '/console redis:flushall --env=test -n');

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
                Package $twigPackage,
                Package $packagistPackage,
                Package $symfonyPackage
            ) {
                $downloadManager = $container->get('packagist.download_manager');

                /* @var $downloadManager DownloadManager */

                for ($i = 0; $i < 25; $i += 1) {
                    $downloadManager->addDownload($twigPackage->getId(), 25);
                }
                for ($i = 0; $i < 12; $i += 1) {
                    $downloadManager->addDownload($packagistPackage->getId(), 12);
                }
                for ($i = 0; $i < 42; $i += 1) {
                    $downloadManager->addDownload($symfonyPackage->getId(), 42);
                }
            },
            $orderBys
        );
    }

    /**
     * @param array $orderBys
     *
     * @return array
     */
    protected function commonTestSearchActionOrderBysFavers(
        array $orderBys = array()
    ) {
        $userMock = $this->getMock('Packagist\WebBundle\Entity\User');
        $userMock1 = $this->getMock('Packagist\WebBundle\Entity\User');
        $userMock2 = $this->getMock('Packagist\WebBundle\Entity\User');

        $userMock->method('getId')->will($this->returnValue(1));
        $userMock1->method('getId')->will($this->returnValue(2));
        $userMock2->method('getId')->will($this->returnValue(3));

        return $this->commonTestSearchActionOrderBysAction(
            function (
                ContainerInterface $container,
                Package $twigPackage,
                Package $packagistPackage,
                Package $symfonyPackage
            ) use (
                $userMock,
                $userMock1,
                $userMock2
            ) {
                $favoriteManager = $container->get('packagist.favorite_manager');

                /* @var $favoriteManager FavoriteManager */

                $favoriteManager->markFavorite($userMock, $twigPackage);
                $favoriteManager->markFavorite($userMock1, $twigPackage);

                $favoriteManager->markFavorite($userMock, $packagistPackage);

                $favoriteManager->markFavorite($userMock, $symfonyPackage);
                $favoriteManager->markFavorite($userMock1, $symfonyPackage);
                $favoriteManager->markFavorite($userMock2, $symfonyPackage);
            },
            $orderBys
        );
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
