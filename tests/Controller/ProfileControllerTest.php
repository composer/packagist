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
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ProfileControllerTest extends WebTestCase
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

    public function testEditProfile(): void
    {
        $user = new User;
        $user->setEnabled(true);
        $user->setUsername('test');
        $user->setEmail('test@example.org');
        $user->setPassword('testtest');
        $user->setApiToken('token');
        $user->setGithubId('123456');

        $user->initializeConfirmationToken();
        $user->setPasswordRequestedAt(new \DateTime());

        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/edit');

        $form = $crawler->selectButton('Update')->form();
        $this->client->submit($form, [
            'packagist_user_profile[email]' => $newEmail = 'new-email@example.org',
        ]);

        $this->assertResponseStatusCodeSame(302);

        $em->clear();
        $user = $em->getRepository(User::class)->find($user->getId());
        $this->assertNotNull($user);
        $this->assertSame($newEmail, $user->getEmail());
        $this->assertNull($user->getPasswordRequestedAt());
        $this->assertNull($user->getConfirmationToken());
    }
}
