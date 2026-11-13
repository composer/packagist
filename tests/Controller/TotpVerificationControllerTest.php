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

use App\Entity\AuditRecord;
use App\Entity\User;
use App\Log\AuditLogEventType;
use App\Security\SecondFactorProofStore;
use App\Tests\IntegrationTestCase;
use App\Tests\Mock\TotpAuthenticatorStub;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;

class TotpVerificationControllerTest extends IntegrationTestCase
{
    private User $user;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set($this->clock = new MockClock('2026-11-05 12:00:00 UTC'));
        $this->user = self::createUser('verify', 'verify@example.org');
        $this->user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $this->user->setBackupCode('abc12345');
        $this->store($this->user);
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testRememberedSessionCanVerifyTotpAndRemoveAuthenticatorWithoutSigningInAgain(): void
    {
        $this->client->request('GET', '/profile/security/authenticator');
        $session = $this->client->getSession();
        self::assertNotNull($session);
        $session->set('_security_main', serialize(new RememberMeToken($this->user, 'main')));
        $session->save();
        $this->assertRemovalRequiresVerification();
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->selectButton('Confirm identity'));
        self::assertCount(0, $crawler->filter('input[type="password"]'));
        $crawler = $this->client->submit($crawler->selectButton('Confirm identity')->form(), ['verify_totp[code]' => '123456']);
        self::assertResponseStatusCodeSame(422);
        $this->assertRemovalRequiresVerification();
        $this->client->submit($crawler->selectButton('Confirm identity')->form(), ['verify_totp[code]' => TotpAuthenticatorStub::MOCKED_VALID_CODE]);
        self::assertResponseRedirects();
        self::assertStringStartsWith('/users/verify/totp/disable?_hash=', (string) $this->client->getResponse()->headers->get('Location'));
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Remove authenticator app')->form();
        $this->clock->sleep(SecondFactorProofStore::RECENT_AUTHENTICATION_LIFETIME);
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertStringStartsWith('/profile/security/verify-totp?return_to=', (string) $this->client->getResponse()->headers->get('Location'));
        $crawler = $this->verify();
        $this->client->submit($crawler->selectButton('Remove authenticator app')->form());
        self::assertResponseRedirects('/users/verify/totp/');
        $user = self::getEM()->find(User::class, $this->user->getId());
        self::assertNotNull($user);
        self::assertFalse($user->isTotpAuthenticationEnabled());
    }

    public function testVerificationReturnsToConfirmationWithoutRemovingAuthenticator(): void
    {
        $crawler = $this->verify();
        self::assertTrue($this->user->isTotpAuthenticationEnabled());
        self::assertTrue($this->user->isBackupCode('abc12345'));
        $this->client->submit($crawler->selectButton('Remove authenticator app')->form());
        self::assertResponseRedirects('/users/verify/totp/');
        $user = self::getEM()->find(User::class, $this->user->getId());
        self::assertNotNull($user);
        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertFalse($user->isBackupCode('abc12345'));
    }

    public function testExpiredProofCannotBeUsedWithAnExistingRemovalForm(): void
    {
        $crawler = $this->verify();
        $form = $crawler->selectButton('Remove authenticator app')->form();
        $this->clock->sleep(SecondFactorProofStore::RECENT_AUTHENTICATION_LIFETIME);
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertStringStartsWith('/profile/security/verify-totp?return_to=', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertTrue($this->user->isTotpAuthenticationEnabled());
    }

    #[TestWith(['invalid'])]
    #[TestWith([''])]
    public function testRecentProofStillRequiresRemovalCsrfToken(string $token): void
    {
        $this->verify();
        $this->client->request('POST', '/users/verify/totp/disable', ['token' => $token]);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->user->isTotpAuthenticationEnabled());
    }

    public function testGetRequestWithValidTokenCannotRemoveAuthenticator(): void
    {
        $crawler = $this->verify();
        $token = $crawler->selectButton('Remove authenticator app')->form()['token']->getValue();
        $this->client->request('GET', '/users/verify/totp/disable', ['token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->user->isTotpAuthenticationEnabled());
        self::assertTrue($this->user->isBackupCode('abc12345'));
    }

    #[TestWith(['123456'])]
    #[TestWith(['abc12345'])]
    public function testInvalidAndBackupCodesDoNotGrantProof(string $code): void
    {
        $this->client->request('GET', '/users/verify/totp/disable');
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Confirm identity')->form(), ['verify_totp[code]' => $code]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="verify_totp"]', 'Invalid authenticator code');
        $this->assertRemovalRequiresVerification();
    }

    public function testCodeWithSpacesIsAccepted(): void
    {
        $this->client->request('GET', '/users/verify/totp/disable');
        $crawler = $this->client->followRedirect();
        $field = $crawler->filter('#verify_totp_code');
        self::assertNull($field->attr('pattern'));
        self::assertNull($field->attr('maxlength'));
        $this->client->submit($crawler->selectButton('Confirm identity')->form(), ['verify_totp[code]' => '999 999']);
        self::assertResponseRedirects();
        self::assertStringStartsWith('/users/verify/totp/disable?_hash=', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[TestWith([''])]
    #[TestWith(['?return_to=/profile/security/keys'])]
    public function testVerificationPageRequiresASignedReturnUrl(string $query): void
    {
        $this->client->request('GET', '/profile/security/verify-totp'.$query);
        self::assertResponseStatusCodeSame(403);
    }

    public function testInvalidCsrfTokenDoesNotGrantProofOrCountAsACodeFailure(): void
    {
        $this->client->request('GET', '/users/verify/totp/disable');
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Confirm identity')->form(), ['verify_totp[code]' => TotpAuthenticatorStub::MOCKED_VALID_CODE, 'verify_totp[_token]' => 'invalid']);
        self::assertResponseStatusCodeSame(422);
        self::assertNull(self::redisCache()->get('2fa-failures:verify'));
        $this->assertRemovalRequiresVerification();
    }

    #[TestWith([''])]
    #[TestWith(['/profile/security/keys?_hash=invalid'])]
    public function testMissingOrInvalidReturnUrlDoesNotGrantProof(string $returnUrl): void
    {
        $this->client->request('GET', '/users/verify/totp/disable');
        $crawler = $this->client->followRedirect();
        $form = $crawler->selectButton('Confirm identity')->form();
        $this->client->request('POST', '/profile/security/verify-totp?'.http_build_query(['return_to' => $returnUrl]), [
            'verify_totp' => ['_token' => $form['verify_totp[_token]']->getValue(), 'code' => TotpAuthenticatorStub::MOCKED_VALID_CODE],
        ]);
        self::assertResponseStatusCodeSame(403);
        $this->assertRemovalRequiresVerification();
    }

    public function testVerificationFailuresShareTheLoginRateLimit(): void
    {
        $this->client->request('GET', '/users/verify/totp/disable');
        $crawler = $this->client->followRedirect();
        self::redisCache()->set('2fa-failures:verify', '4');
        $crawler = $this->client->submit($crawler->selectButton('Confirm identity')->form(), ['verify_totp[code]' => '123456']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('5', self::redisCache()->get('2fa-failures:verify'));
        $this->client->submit($crawler->selectButton('Confirm identity')->form(), ['verify_totp[code]' => TotpAuthenticatorStub::MOCKED_VALID_CODE]);
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('Retry-After', '900');
        $this->assertRemovalRequiresVerification();
    }

    public function testAdministratorsMustVerifyWhenRemovingTheirOwnAuthenticator(): void
    {
        $this->user->addRole('ROLE_DISABLE_2FA');
        self::getEM()->flush();
        $this->client->loginUser($this->user);
        $this->assertRemovalRequiresVerification();
    }

    public function testVerificationCannotAuthorizeRemovalForAnotherAccount(): void
    {
        $other = self::createUser('other', 'other@example.org');
        $other->setTotpSecret('JBSWY3DPEHPK3PXP');
        $this->store($other);
        $this->verify();
        $this->client->request('GET', '/users/other/totp/disable');
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($other->isTotpAuthenticationEnabled());
    }

    public function testResubmittedRemovalFormDoesNotRemoveTwice(): void
    {
        $form = $this->verify()->selectButton('Remove authenticator app')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/users/verify/totp/');

        $this->client->submit($form);
        self::assertResponseRedirects('/users/verify/totp/');
        self::assertCount(1, self::getEM()->getRepository(AuditRecord::class)->findBy(['type' => AuditLogEventType::TotpAuthenticationDeactivated]));
    }

    public function testAccountWithoutTotpReturnsToAuthenticatorSettings(): void
    {
        $this->user->setTotpSecret(null);
        self::getEM()->flush();
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/profile/security/verify-totp');
        self::assertResponseRedirects('/profile/security/authenticator');
    }

    private function verify(): Crawler
    {
        $this->client->request('GET', '/users/verify/totp/disable');
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Confirm identity')->form(), ['verify_totp[code]' => TotpAuthenticatorStub::MOCKED_VALID_CODE]);
        self::assertResponseRedirects();
        self::assertStringStartsWith('/users/verify/totp/disable?_hash=', (string) $this->client->getResponse()->headers->get('Location'));

        return $this->client->followRedirect();
    }

    private function assertRemovalRequiresVerification(): void
    {
        $this->client->request('GET', '/users/verify/totp/disable');
        self::assertResponseRedirects();
        self::assertStringStartsWith('/profile/security/verify-totp?return_to=', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertTrue($this->user->isTotpAuthenticationEnabled());
    }
}
