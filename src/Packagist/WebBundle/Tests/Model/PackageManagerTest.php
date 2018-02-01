<?php

namespace Packagist\WebBundle\Tests\Model;

use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Model\PackageManager;

class PackageManagerTest extends \PHPUnit_Framework_TestCase
{
    public function testNotifyFailure()
    {
        $this->markTestSkipped('Do it!');

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
}
