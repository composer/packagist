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

namespace App\Tests;

use App\Entity\Package;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

class IntegrationTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot(); // prevent reboot to keep the transaction

        static::getContainer()->get(Connection::class)->beginTransaction();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(Connection::class)->rollBack();

        parent::tearDown();
    }

    public static function getEM(): EntityManagerInterface
    {
        return static::getContainer()->get(ManagerRegistry::class)->getManager();
    }

    protected function assertFormError(string $message, string $formName, Crawler $crawler): void
    {
        $formCrawler = $crawler->filter(\sprintf('[name="%s"]', $formName));
        $this->assertCount(
            1,
            $formCrawler->filter('.alert-danger:contains("'.$message.'")'),
            $formCrawler->html()."\nShould find an .alert-danger within the form with the message: '$message'",
        );
    }

    /**
     * @param object|array<object> $objects
     */
    protected function store(array|object ...$objects): void
    {
        $em = $this->getEM();
        foreach ($objects as $obj) {
            if (\is_array($obj)) {
                foreach ($obj as $obj2) {
                    $em->persist($obj2);
                }
            } else {
                $em->persist($obj);
            }
        }

        $em->flush();
    }

    /**
     * Creates a Package entity without running the slow network-based repository initialization step
     *
     * @param array<User> $maintainers
     */
    protected static function createPackage(string $name, string $repository, ?string $remoteId = null, array $maintainers = []): Package
    {
        $package = new Package();

        $package->setName($name);
        $package->setRemoteId($remoteId);
        new \ReflectionProperty($package, 'repository')->setValue($package, $repository);
        if (\count($maintainers) > 0) {
            foreach ($maintainers as $user) {
                $package->addMaintainer($user);
                $user->addPackage($package);
            }
        }

        return $package;
    }

    /**
     * @param array<string> $roles
     */
    protected static function createUser(string $username = 'test', string $email = 'test@example.org', string $password = 'testtest', string $apiToken = 'api-token', string $safeApiToken = 'safe-api-token', string $githubId = '12345', bool $enabled = true, array $roles = []): User
    {
        $user = new User();
        $user->setEnabled(true);
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setPassword($password);
        $user->setApiToken($apiToken);
        $user->setSafeApiToken($safeApiToken);
        $user->setGithubId($githubId);
        $user->setRoles($roles);

        return $user;
    }
}
