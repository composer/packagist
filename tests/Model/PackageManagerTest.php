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

namespace App\Tests\Model;

use App\Entity\Package;
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
            ->with($this->equalTo(['username' => 'test', 'apiToken' => 'token']))
            ->will($this->returnValue($user));

        static::$kernel->getContainer()->set('test.user_repo', $repo);
        static::$kernel->getContainer()->set('doctrine.orm.entity_manager', $em);
        static::$kernel->getContainer()->set('App\Package\Updater', $updater);

        $payload = json_encode(['repository' => ['url' => 'git://github.com/composer/composer']]);
        $client->request('POST', '/api/github?username=test&apiToken=token', ['payload' => $payload]);
        $this->assertEquals(202, $client->getResponse()->getStatusCode());
    }
}
