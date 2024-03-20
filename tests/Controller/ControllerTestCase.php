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
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

class ControllerTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    public function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot(); // prevent reboot to keep the transaction

        static::getContainer()->get(Connection::class)->beginTransaction();

        parent::setUp();
    }

    public function tearDown(): void
    {
        static::getContainer()->get(Connection::class)->rollBack();

        parent::tearDown();
    }

    public function getEM(): EntityManagerInterface
    {
        return static::getContainer()->get(ManagerRegistry::class)->getManager();
    }

    protected function assertFormError(string $message, string $formName, Crawler $crawler): void
    {
        $formCrawler = $crawler->filter(sprintf('[name="%s"]', $formName));
        $this->assertCount(
            1,
            $formCrawler->filter('.alert-danger:contains("' . $message . '")'),
            $formCrawler->html()."\nShould find an .alert-danger within the form with the message: '$message'",
        );
    }

    /**
     * Creates a Package entity without running the slow network-based repository initialization step
     */
    protected function createPackage(string $name, string $repository, ?string $remoteId = null)
    {
        $package = new Package();

        $package->setName($name);
        $package->setRemoteId($remoteId);
        (new ReflectionProperty($package, 'repository'))->setValue($package, $repository);

        return $package;
    }
}
