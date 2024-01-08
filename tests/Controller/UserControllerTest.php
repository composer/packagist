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

use App\Entity\User;
use App\Tests\Mock\TotpAuthenticatorStub;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    public function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot(); // Prevent reboot between requests
        static::getContainer()->get(Connection::class)->beginTransaction();

        parent::setUp();
    }

    public function tearDown(): void
    {
        static::getContainer()->get(Connection::class)->rollBack();

        parent::tearDown();
    }

    public function testEnableTwoFactoCode(): void
    {
        $user = new User;
        $user->setEnabled(true);
        $user->setUsername('test');
        $user->setEmail('test@example.org');
        $user->setPassword('testtest');
        $user->setApiToken('token');

        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', sprintf('/users/%s/2fa/enable', $user->getUsername()));
        $form = $crawler->selectButton('Enable Two-Factor Authentication')->form();
        $form->setValues([
            'enable_two_factor_auth[code]' => 123456,
        ]);

        $crawler = $this->client->submit($form);
        $this->assertResponseStatusCodeSame(422);

        $form = $crawler->selectButton('Enable Two-Factor Authentication')->form();
        $form->setValues([
            'enable_two_factor_auth[code]' => TotpAuthenticatorStub::MOCKED_VALID_CODE,
        ]);

        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(302);

        $em->clear();
        $this->assertTrue($em->getRepository(User::class)->find($user->getId())->isTotpAuthenticationEnabled());
    }
}
