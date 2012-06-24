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

    public function testGithubFailsCorrectly()
    {
        $client = self::createClient();

        $client->request('GET', '/api/github');
        $this->assertEquals(405, $client->getResponse()->getStatusCode(), 'GET method should not be allowed for GitHub Post-Receive URL');

        $payload = json_encode(array('repository' => array('url' => 'git://github.com/composer/composer',)));
        $client->request('POST', '/api/github?username=INVALID_USER&apiToken=INVALID_TOKEN', array('payload' => $payload,));
        $this->assertEquals(403, $client->getResponse()->getStatusCode(), 'POST method should return 403 "Forbidden" if invalid username and API Token are sent');
    }

    public function testGithubApi()
    {
        $client = self::createClient();

        $package = new Package;
        $package->setRepository('http://github.com/composer/composer.git');

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
}