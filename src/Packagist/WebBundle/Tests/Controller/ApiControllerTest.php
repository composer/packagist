<?php

namespace Packagist\WebBundle\Tests\Controller;

use Packagist\WebBundle\Entity\User;
use Packagist\WebBundle\Entity\Package;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApiControllerTest extends WebTestCase
{
    public function testPackages()
    {
        $client = self::createClient();

        $client->request('GET', '/packages.json');
        $this->assertTrue(count(json_decode($client->getResponse()->getContent())) > 0);
    }

    /**
     * @group api
     */
    public function testGithubFailsCorrectly()
    {
        $client = self::createClient();

        $client->request('GET', '/api/github');
        $this->assertEquals(405, $client->getResponse()->getStatusCode(), 'GET method should not be allowed for GitHub Post-Receive URL');

        $payload = json_encode(array('repository' => array('url' => 'git://github.com/composer/composer',)));
        $client->request('POST', '/api/github?username=INVALID_USER&apiToken=INVALID_TOKEN', array('payload' => $payload,));
        $this->assertEquals(403, $client->getResponse()->getStatusCode(), 'POST method should return 403 "Forbidden" if invalid username and API Token are sent');
    }

    /**
     * @dataProvider githubApiProvider
     * @group api
     */
    public function testGithubApi($url)
    {
        $client = self::createClient();

        $package = new Package;
        $package->setRepository($url);

        $user = new User;
        $user->addPackages($package);

        $repo = $this->getMockBuilder('Packagist\WebBundle\Entity\UserRepository')->disableOriginalConstructor()->getMock();
        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')->disableOriginalConstructor()->getMock();
        $updater = $this->getMockBuilder('Packagist\WebBundle\Package\Updater')->disableOriginalConstructor()->getMock();

        $repo->expects($this->once())
            ->method('findOneBy')
            ->with($this->equalTo(array('username' => 'test', 'apiToken' => 'token')))
            ->will($this->returnValue($user));

        static::$kernel->getContainer()->set('packagist.user_repository', $repo);
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', $em);
        static::$kernel->getContainer()->set('packagist.package_updater', $updater);

        $payload = json_encode(array('repository' => array('url' => 'git://github.com/composer/composer')));
        $client->request('POST', '/api/github?username=test&apiToken=token', array('payload' => $payload));
        $this->assertEquals(202, $client->getResponse()->getStatusCode());
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
     * @group api
     */
    public function testGitlabFailsCorrectly()
    {
        $client = self::createClient();

        $client->request('GET', '/api/gitlab');
        $this->assertEquals(405, $client->getResponse()->getStatusCode(), 'GET method should not be allowed for GitHub Post-Receive URL');

        $payload = json_encode(array('repository' => array('url' => 'git://gitlab.com/composer/composer.git',)));
        $client->request('POST', '/api/gitlab?username=INVALID_USER&apiToken=INVALID_TOKEN', array(), array(), array(), $payload);
        $this->assertEquals(403, $client->getResponse()->getStatusCode(), 'POST method should return 403 "Forbidden" if invalid username and API Token are sent');
    }

    /**
     * @dataProvider gitlabApiProvider
     * @group api
     */
    public function testGitlabApi($url)
    {
        $client = self::createClient();

        $package = new Package;
        $package->setRepository($url);

        $user = new User;
        $user->addPackages($package);

        $repo = $this->getMockBuilder('Packagist\WebBundle\Entity\UserRepository')->disableOriginalConstructor()->getMock();
        $em = $this->getMockBuilder('Doctrine\ORM\EntityManager')->disableOriginalConstructor()->getMock();
        $updater = $this->getMockBuilder('Packagist\WebBundle\Package\Updater')->disableOriginalConstructor()->getMock();

        $repo->expects($this->once())
            ->method('findOneBy')
            ->with($this->equalTo(array('username' => 'test', 'apiToken' => 'token')))
            ->will($this->returnValue($user));

        static::$kernel->getContainer()->set('packagist.user_repository', $repo);
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', $em);
        static::$kernel->getContainer()->set('packagist.package_updater', $updater);

        $payload = json_encode(array('repository' => array('url' => 'git://gitlab.com/composer/composer.git')));
        $client->request('POST', '/api/gitlab?username=test&apiToken=token', array(), array(), array(), $payload);
        $this->assertEquals(202, $client->getResponse()->getStatusCode());
    }

    public function gitlabApiProvider()
    {
        return array(
            array('https://gitlab.com/composer/composer.git'),
            array('http://gitlab.com/composer/composer.git'),
            array('git@gitlab.com:composer/composer.git'),
        );
    }

    /**
     * @depends      testGithubFailsCorrectly
     * @dataProvider urlProvider
     * @group api
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

        if ($endpoint == 'gitlab') {
            $client->request('POST', '/api/gitlab?username=INVALID_USER&apiToken=INVALID_TOKEN', array(), array(), array(), $payload);
        } else {
            $client->request('POST', '/api/'.$endpoint.'?username=INVALID_USER&apiToken=INVALID_TOKEN', array('payload' => $payload));
        }

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
            array('github', 'git@github.com:user/repo.git', true),
            array('github', 'git@github.com:user/repo', true),

            // valid bitbucket URLs
            array('bitbucket', 'bitbucket.org/user/repo', true),
            array('bitbucket', 'http://bitbucket.org/user/repo', true),
            array('bitbucket', 'https://bitbucket.org/user/repo', true),

            // valid gitlab URLs
            array('gitlab', 'https://gitlab.com/user/repo.git', true),
            array('gitlab', 'git://gitlab.com/user/repo.git', true),
            array('gitlab', 'git@gitlab.com/user/repo.git', true),
            array('gitlab', 'https://self-hosted-gitlab.com/user/repo.git', true),
            array('gitlab', 'https://gitlab.company.be/user/repo.git', true),

            // invalid github URLs
            array('github', 'php://github.com/user/repository', false),
            array('github', 'javascript://github.com/user/repository', false),
            array('github', 'http://', false),
            array('github', 'http://thisisnotgithub.com/user/repository', false),
            array('github', 'http://thisisnotbitbucket.org/user/repository', false),
            array('github', 'githubcom/user/repository', false),
            array('github', 'githubXcom/user/repository', false),
            array('github', 'https://github.com/user/', false),
            array('github', 'https://github.com/user', false),
            array('github', 'https://github.com/', false),
            array('github', 'https://github.com', false),

            // invalid bitbucket URLs
            array('bitbucket', 'bitbucketorg/user/repository', false),
            array('bitbucket', 'bitbucketXorg/user/repository', false),

            // invalid gitlab URLs
            array('gitlab', 'gitlab.com/user/repo.git', false),
            array('gitlab', 'https://gitlab.com/user/repo', false),
            array('gitlab', 'git@gitlab.com/user/repo', false),
            array('gitlab', 'git://gitlab.com/user/repo', false),
        );
    }
}
