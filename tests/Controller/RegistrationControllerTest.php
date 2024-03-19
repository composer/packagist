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
use App\Validator\NotProhibitedPassword;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

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

    #[TestWith(['max.example'])]
    #[TestWith(['max@example.com'])]
    #[TestWith(['Max@Example.com'])]
    public function testRegisterWithTooSimplePasswords(string $password): void
    {
        $crawler = $this->client->request('GET', '/register/');
        $this->assertResponseStatusCodeSame(200);

        $form = $crawler->filter('[name="registration_form"]')->form();
        $form->setValues([
            'registration_form[email]' => 'Max@Example.com',
            'registration_form[username]' => 'max.example',
            'registration_form[plainPassword]' => $password,
        ]);

        $crawler = $this->client->submit($form);
        $this->assertResponseStatusCodeSame(422, 'Should be invalid because password is the same as email or username');

        $this->assertFormError((new NotProhibitedPassword)->message, $crawler);
    }

    private function assertFormError(string $message, Crawler $crawler): void
    {
        $formCrawler = $crawler->filter('[name="registration_form"]');
        $this->assertCount(
            1,
            $formCrawler->filter('.alert-danger:contains("' . $message . '")'),
            $formCrawler->html()."\nShould find an .alert-danger within the form with the message: '$message'",
        );
    }
}
