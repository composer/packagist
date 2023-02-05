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

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use App\Entity\Package;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApiControllerTest extends WebTestCase
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

    public function testGithubFailsOnGet(): void
    {
        $this->client->request('GET', '/api/github');
        $this->assertEquals(405, $this->client->getResponse()->getStatusCode(), 'GET method should not be allowed for GitHub Post-Receive URL');
    }

    public function testGitHubFailsWithInvalidCredentials(): void
    {
        $payload = json_encode(['repository' => ['url' => 'git://github.com/composer/composer']]);
        $this->client->request('POST', '/api/github?username=INVALID_USER&apiToken=INVALID_TOKEN', ['payload' => $payload]);
        $this->assertEquals(403, $this->client->getResponse()->getStatusCode(), 'POST method should return 403 "Forbidden" if invalid username and API Token are sent: '.$this->client->getResponse()->getContent());
    }

    #[DataProvider('githubApiProvider')]
    public function testGithubApi($url): void
    {
        $package = new Package;
        $package->setName('test/'.md5(uniqid()));
        $package->setRepository($url);

        $user = new User;
        $user->addPackage($package);
        $user->setEnabled(true);
        $user->setUsername('test');
        $user->setEmail('test@example.org');
        $user->setPassword('testtest');
        $user->setApiToken('token');

        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $em->persist($package);
        $em->persist($user);
        $em->flush();

        $scheduler = $this->createMock('App\Service\Scheduler');

        $scheduler->expects($this->once())
            ->method('scheduleUpdate')
            ->with($package);

        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', $em);
        static::$kernel->getContainer()->set('App\Service\Scheduler', $scheduler);

        $payload = json_encode(['repository' => ['url' => 'git://github.com/composer/composer']]);
        $this->client->request('POST', '/api/github?username=test&apiToken=token', ['payload' => $payload]);
        $this->assertEquals(202, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    public static function githubApiProvider(): array
    {
        return [
            ['https://github.com/composer/composer.git'],
            ['http://github.com/composer/composer.git'],
            ['http://github.com/composer/composer'],
            ['git@github.com:composer/composer.git'],
        ];
    }

    #[Depends('testGitHubFailsWithInvalidCredentials')]
    #[DataProvider('urlProvider')]
    public function testUrlDetection($endpoint, $url, $expectedOK): void
    {
        if ($endpoint == 'bitbucket') {
            $canonUrl = substr($url, 0, 1);
            $absUrl = substr($url, 1);
            $payload = json_encode(['canon_url' => $canonUrl, 'repository' => ['absolute_url' => $absUrl]]);
        } else {
            $payload = json_encode(['repository' => ['url' => $url]]);
        }

        $this->client->request('POST', '/api/'.$endpoint.'?username=INVALID_USER&apiToken=INVALID_TOKEN', ['payload' => $payload]);

        $status = $this->client->getResponse()->getStatusCode();

        if (!$expectedOK) {
            $this->assertEquals(406, $status, 'POST method should return 406 "Not Acceptable" if an unknown URL was sent');
        } else {
            $this->assertEquals(403, $status, 'POST method should return 403 "Forbidden" for a valid URL with bad credentials.');
        }
    }

    public static function urlProvider(): array
    {
        return [
            // valid github URLs
            ['github', 'github.com/user/repo', true],
            ['github', 'github.com/user/repo.git', true],
            ['github', 'http://github.com/user/repo', true],
            ['github', 'https://github.com/user/repo', true],
            ['github', 'https://github.com/user/repo.git', true],
            ['github', 'git://github.com/user/repo', true],
            ['github', 'git://github.com/User/Repo.git', true],
            ['github', 'git@github.com:user/repo.git', true],
            ['github', 'git@github.com:user/repo', true],
            ['github', 'https://github.com/user/repo/', true],
            ['github', 'https://github.com/user/', true], // not strictly valid but marked as valid due to support for https://example.org/some-repo.git

            // valid bitbucket URLs
            ['bitbucket', 'bitbucket.org/user/repo', true],
            ['bitbucket', 'http://bitbucket.org/user/repo', true],
            ['bitbucket', 'https://bitbucket.org/user/repo', true],

            // valid others
            ['update-package', 'https://ghe.example.org/user/repository', true],
            ['update-package', 'https://example.org/some-repo.git', true],
            ['update-package', 'https://gitlab.org/user/repository', true],
            ['update-package', 'https://gitlab.org/user/sub/group/lala/repository', true],
            ['update-package', 'ssh://git@stash.xxxxx.com/uuuuu/qqqqq.git', true],
            ['update-package', 'ssh://git@stash.xxxxx.com:2222/uuuuu/qqqqq.git', true],
            ['update-package', 'ssh://git@stash.zzzzz.com/kkkkk.git', true],

            // invalid URLs
            ['github', 'php://github.com/user/repository', false],
            ['github', 'javascript://github.com/user/repository', false],
            ['github', 'http://', false],
            ['github', 'https://github.com/', false],
            ['github', 'https://github.com', false],
            ['update-package', 'ssh://ghe.example.org/user/jjjjj.git', false],
        ];
    }
}
