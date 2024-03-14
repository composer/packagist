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
use Scheb\TwoFactorBundle\Security\Http\Authenticator\TwoFactorAuthenticator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class RegistrationControllerTest extends WebTestCase
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

    public function testRegisterWithoutOAuth(): void
    {
        $crawler = $this->client->request('GET', '/register/');
        $this->assertResponseStatusCodeSame(200);

        $form = $crawler->filter('[name="registration_form"]')->form();
        $form->setValues([
            'registration_form[email]' => 'Max@Example.com',
            'registration_form[username]' => 'max.example',
            'registration_form[plainPassword]' => 'SuperSecret123',
        ]);

        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(302);

        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'max.example']);
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('max@example.com', $user->getEmailCanonical(), "user email should have been canonicalized");
    }
}
