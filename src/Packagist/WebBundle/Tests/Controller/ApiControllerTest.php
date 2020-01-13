<?php

namespace Packagist\WebBundle\Tests\Controller;

use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApiControllerTest extends WebTestCase
{
    public function testGithubFailsCorrectly()
    {
        $client = self::createClient();

        $client->request('GET', '/api/github');
        $this->assertEquals(405, $client->getResponse()->getStatusCode(), 'GET method should not be allowed for GitHub Post-Receive URL');

        $payload = json_encode(array('repository' => array('url' => 'git://github.com/composer/composer',)));
        $client->request('POST', '/api/github?username=INVALID_USER&apiToken=INVALID_TOKEN', array('payload' => $payload,));
        $this->assertEquals(403, $client->getResponse()->getStatusCode(), 'POST method should return 403 "Forbidden" if invalid username and API Token are sent: '.$client->getResponse()->getContent());
    }

    /**
     * @dataProvider githubApiProvider
     */
    public function testGithubApi($url)
    {
        $client = self::createClient();

        $package = new Package;
        $package->setRepository($url);

        $ref = new \ReflectionProperty(Package::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($package, 1);

        $user = new User;
        $user->addPackages($package);
        $user->setEnabled(true);

        $repo = $this->getMockBuilder('Packagist\WebBundle\Entity\UserRepository')->disableOriginalConstructor()->getMock();
        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')->disableOriginalConstructor()->getMock();
        $updater = $this->getMockBuilder('Packagist\WebBundle\Package\Updater')->disableOriginalConstructor()->getMock();

        $repo->expects($this->once())
            ->method('findOneBy')
            ->with($this->equalTo(array('username' => 'test', 'apiToken' => 'token')))
            ->will($this->returnValue($user));

        static::$kernel->getContainer()->set('test.user_repo', $repo);
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', $em);
        static::$kernel->getContainer()->set('packagist.package_updater', $updater);

        $payload = json_encode(array('repository' => array('url' => 'git://github.com/composer/composer')));
        $client->request('POST', '/api/github?username=test&apiToken=token', array('payload' => $payload));
        $this->assertEquals(202, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
    }

    public function githubApiProvider()
    {
        return array(
            array('https://github.com/composer/composer.git'),
            array('http://github.com/composer/composer.git'),
            array('http://github.com/composer/composer'),
            array('git@github.com:composer/composer.git'),
        );
    }

    /**
     * @depends testGithubFailsCorrectly
     * @dataProvider urlProvider
     */
    public function testUrlDetection($endpoint, $url, $expectedOK)
    {
        $client = self::createClient();

        if ($endpoint == 'bitbucket') {
            $canonUrl = substr($url, 0, 1);
            $absUrl = substr($url, 1);
            $payload = json_encode(array('canon_url' => $canonUrl, 'repository' => array('absolute_url' => $absUrl)));
        } else {
            $payload = json_encode(array('repository' => array('url' => $url)));
        }

        $client->request('POST', '/api/'.$endpoint.'?username=INVALID_USER&apiToken=INVALID_TOKEN', array('payload' => $payload));

        $status = $client->getResponse()->getStatusCode();

        if (!$expectedOK) {
            $this->assertEquals(406, $status, 'POST method should return 406 "Not Acceptable" if an unknown URL was sent');
        } else {
            $this->assertEquals(403, $status, 'POST method should return 403 "Forbidden" for a valid URL with bad credentials.');
        }
    }

    public function urlProvider()
    {
        return array(
            // valid github URLs
            array('github', 'github.com/user/repo', true),
            array('github', 'github.com/user/repo.git', true),
            array('github', 'http://github.com/user/repo', true),
            array('github', 'https://github.com/user/repo', true),
            array('github', 'https://github.com/user/repo.git', true),
            array('github', 'git://github.com/user/repo', true),
            array('github', 'git://github.com/User/Repo.git', true),
            array('github', 'git@github.com:user/repo.git', true),
            array('github', 'git@github.com:user/repo', true),
            array('github', 'https://github.com/user/repo/', true),
            array('github', 'https://github.com/user/', true), // not strictly valid but marked as valid due to support for https://example.org/some-repo.git

            // valid bitbucket URLs
            array('bitbucket', 'bitbucket.org/user/repo', true),
            array('bitbucket', 'http://bitbucket.org/user/repo', true),
            array('bitbucket', 'https://bitbucket.org/user/repo', true),

            // valid others
            array('update-package', 'https://ghe.example.org/user/repository', true),
            array('update-package', 'https://example.org/some-repo.git', true),
            array('update-package', 'https://gitlab.org/user/repository', true),
            array('update-package', 'https://gitlab.org/user/sub/group/lala/repository', true),
            array('update-package', 'ssh://git@stash.xxxxx.com/uuuuu/qqqqq.git', true),
            array('update-package', 'ssh://git@stash.xxxxx.com:2222/uuuuu/qqqqq.git', true),
            array('update-package', 'ssh://git@stash.zzzzz.com/kkkkk.git', true),

            // invalid URLs
            array('github', 'php://github.com/user/repository', false),
            array('github', 'javascript://github.com/user/repository', false),
            array('github', 'http://', false),
            array('github', 'https://github.com/', false),
            array('github', 'https://github.com', false),
            array('update-package', 'ssh://ghe.example.org/user/jjjjj.git', false),
        );
    }
}
