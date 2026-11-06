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
use App\Security\Voter\SecondFactorVoter;
use App\Tests\IntegrationTestCase;
use App\Tests\Mock\TotpAuthenticatorStub;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class SecondFactorAuthenticationTest extends IntegrationTestCase
{
    private const string PASSWORD = 'existing-test-password';
    private User $user;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        self::getContainer()->set('scheb_two_factor.security.totp_authenticator', self::getContainer()->get(TotpAuthenticatorStub::class));
        Clock::set($this->clock = new MockClock('2026-10-28 12:00:00 UTC'));
        self::getContainer()->get('cache.app')->deleteItem('scheb_two_factor_code_reuse.'.sha1('verify.'.TotpAuthenticatorStub::MOCKED_VALID_CODE));
        $this->user = self::createUser('verify', 'verify@example.org');
        $this->user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $this->user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->user, self::PASSWORD));
        $this->store($this->user);
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testPrimaryLoginDoesNotPassSecondFactorChecks(): void
    {
        $this->assertAuthenticationChecks(false, false);
    }

    public function testSuccessfulLoginCodeSetsAuthenticationAndRecencyIndependently(): void
    {
        $crawler = $this->beginLogin();
        $this->client->submit($crawler->filter('form[action="/2fa-check"]')->form(), ['_auth_code' => TotpAuthenticatorStub::MOCKED_VALID_CODE]);
        self::assertResponseRedirects();
        $this->assertAuthenticationChecks(true, true);
        $this->client->request('GET', '/users/verify/totp/disable');
        self::assertResponseIsSuccessful();
        $this->clock->sleep(599);
        $this->assertAuthenticationChecks(true, true);
        $this->clock->sleep(1);
        $this->assertAuthenticationChecks(true, false);
        $this->client->request('GET', '/users/verify/totp/disable');
        self::assertResponseRedirects('/profile/security/verify-totp');
    }

    public function testTrustedDeviceDoesNotCountAsSecondFactorAuthentication(): void
    {
        $crawler = $this->beginLogin();
        $this->client->submit($crawler->filter('form[action="/2fa-check"]')->form(), [
            '_auth_code' => TotpAuthenticatorStub::MOCKED_VALID_CODE, '_trusted' => 'on',
        ]);
        $trusted = $this->client->getCookieJar()->get('trusted_device');
        self::assertNotNull($trusted);
        $this->assertAuthenticationChecks(true, true);
        $this->client->restart();
        $this->client->getCookieJar()->set($trusted);
        $this->client->request('POST', '/login/', ['_username' => 'verify', '_password' => self::PASSWORD]);
        $this->assertAuthenticationChecks(false, false);
    }

    public function testRememberMeRestorationClearsOldProofAndAllowsFreshTotpVerification(): void
    {
        $this->client->restart();
        $this->client->request('POST', '/login/', ['_username' => 'verify', '_password' => self::PASSWORD, '_remember_me' => 'on']);
        $crawler = $this->client->request('GET', '/2fa');
        $this->client->submit($crawler->filter('form[action="/2fa-check"]')->form(), ['_auth_code' => TotpAuthenticatorStub::MOCKED_VALID_CODE]);
        self::assertNotNull($this->client->getCookieJar()->get('pauth'));
        $this->assertAuthenticationChecks(true, true);
        $session = $this->client->getSession();
        self::assertNotNull($session);
        $session->remove('_security_main');
        $session->save();
        $this->assertAuthenticationChecks(false, false);
        self::assertInstanceOf(RememberMeToken::class, self::getContainer()->get('security.token_storage')->getToken());
        $crawler = $this->client->request('GET', '/profile/security/verify-totp');
        $this->client->submit($crawler->selectButton('Confirm identity')->form(), ['verify_totp[code]' => TotpAuthenticatorStub::MOCKED_VALID_CODE]);
        self::assertResponseRedirects('/users/verify/totp/disable');
        $this->assertAuthenticationChecks(true, true);
    }

    public function testBackupCodeLoginDoesNotCountAsAuthenticatorAuthentication(): void
    {
        $this->user->setBackupCode('abc12345');
        self::getEM()->flush();
        $crawler = $this->beginLogin();
        $this->client->submit($crawler->filter('form[action="/2fa-check"]')->form(), ['_auth_code' => 'abc12345']);
        self::assertResponseRedirects();
        $this->assertAuthenticationChecks(false, false);
    }

    private function assertAuthenticationChecks(bool $authenticated, bool $recent): void
    {
        // Evaluate authorization while the request's authenticated session is active.
        $check = static function () use ($authenticated, $recent): void {
            $authorization = self::getContainer()->get(AuthorizationCheckerInterface::class);
            self::assertSame($authenticated, $authorization->isGranted(SecondFactorVoter::IS_AUTHENTICATED_2FA));
            self::assertSame($authenticated, $authorization->isGranted(SecondFactorVoter::IS_AUTHENTICATED_TOTP));
            self::assertSame($recent, $authorization->isGranted(SecondFactorVoter::IS_AUTHENTICATED_2FA_RECENTLY));
            self::assertSame($recent, $authorization->isGranted(SecondFactorVoter::IS_AUTHENTICATED_TOTP_RECENTLY));
        };
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(KernelEvents::RESPONSE, $check);
        try {
            $this->client->request('GET', '/profile/security');
            self::assertResponseIsSuccessful();
        } finally {
            $dispatcher->removeListener(KernelEvents::RESPONSE, $check);
        }
    }

    private function beginLogin(): Crawler
    {
        $this->client->restart();
        $this->client->request('POST', '/login/', ['_username' => 'verify', '_password' => self::PASSWORD]);

        return $this->client->request('GET', '/2fa');
    }
}
