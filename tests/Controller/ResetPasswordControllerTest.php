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

class ResetPasswordControllerTest extends WebTestCase
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

    public function testResetPassword(): void
    {
        $user = $this->setupUserWithPasswordResetRequest(false);
        $oldPassword = $user->getPassword();

        $crawler = $this->client->request('GET', '/reset-password/reset/' . $user->getConfirmationToken());
        $this->assertResponseStatusCodeSame(200);

        $this->submitPasswordResetFormAndAsserStatusCode($crawler, 'new-password', 302);
        $this->assertUserHasNewPassword($user, $oldPassword);
    }

    public function testResetPasswordWithTwoFactor(): void
    {
        $user = $this->setupUserWithPasswordResetRequest(true);
        $oldPassword = $user->getPassword();

        $crawler = $this->client->request('GET', '/reset-password/reset/' . $user->getConfirmationToken());
        $this->assertResponseStatusCodeSame(200);

        $this->submitPasswordResetFormAndAsserStatusCode($crawler, 'new-password', 422);
        $this->submitPasswordResetFormAndAsserStatusCode($crawler, 'new-password', 302, TotpAuthenticatorStub::MOCKED_VALID_CODE);
        $this->assertUserHasNewPassword($user, $oldPassword);

        $this->assertTrue(self::getContainer()->get(TokenStorageInterface::class)->getToken()?->getAttribute(TwoFactorAuthenticator::FLAG_2FA_COMPLETE));
    }

    private function setupUserWithPasswordResetRequest(bool $withTwoFactor): User
    {
        $user = new User;
        $user->setEnabled(true);
        $user->setUsername('test');
        $user->setEmail('test@example.org');
        $user->setPassword('testtest');
        $user->setApiToken('token');
        $user->initializeConfirmationToken();
        $user->setPasswordRequestedAt(new \DateTime());

        if ($withTwoFactor) {
            $user->setTotpSecret('secret');
        }

        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function submitPasswordResetFormAndAsserStatusCode(Crawler $crawler, string $newPassword, int $expectedStatusCode, ?string $mfaCode = null): void
    {
        $form = $crawler->selectButton('Reset password')->form();
        $form->setValues(array_filter([
            'reset_password_form[plainPassword]' => $newPassword,
            'reset_password_form[twoFactorCode]' => $mfaCode,
        ]));

        $this->client->submit($form);
        $this->assertResponseStatusCodeSame($expectedStatusCode);
    }

    private function assertUserHasNewPassword(User $user, ?string $oldPassword): void
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $em->clear();

        $user = $em->getRepository(User::class)->find($user->getId());
        $this->assertNotNull($user);
        $this->assertNotSame($oldPassword, $user->getPassword());
    }
}
