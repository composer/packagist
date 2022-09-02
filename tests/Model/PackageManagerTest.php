<?php

namespace App\Tests\Model;

use App\Entity\Package;
use App\Model\PackageManager;
use PHPUnit\Framework\TestCase;

class PackageManagerTest extends TestCase
{
    public function testNotifyFailure()
    {
        $this->markTestSkipped('Do it!');

        $client = self::createClient();

        $package = new Package;
        $package->setRepository($url);

        $user = new User;
        $user->addPackage($package);

        $repo = $this->createMock('App\Entity\UserRepository');
        $em = $this->createMock('Doctrine\ORM\EntityManager');
        $updater = $this->createMock('App\Package\Updater');

        $repo->expects($this->once())
            ->method('findOneBy')
            ->with($this->equalTo(array('username' => 'test', 'apiToken' => 'token')))
            ->will($this->returnValue($user));

        static::$kernel->getContainer()->set('test.user_repo', $repo);
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', $em);
        static::$kernel->getContainer()->set('App\Package\Updater', $updater);

        $payload = json_encode(array('repository' => array('url' => 'git://github.com/composer/composer')));
        $client->request('POST', '/api/github?username=test&apiToken=token', array('payload' => $payload));
        $this->assertEquals(202, $client->getResponse()->getStatusCode());
    }
}
