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
use App\Entity\SupportRequestRepository;
use App\Entity\User;
use App\Entity\WebAuthnCredential;
use App\Event\SecondFactorsResetEvent;
use App\Log\AuditLogEventType;
use App\Security\BackupCodeManager;
use App\Security\SecondFactorProofStore;
use App\Security\TotpAuthManager;
use App\Support\SupportRequestStatus;
use App\Support\SupportRequestType;
use App\Tests\IntegrationTestCase;
use App\Tests\Mock\TotpAuthenticatorStub;
use App\Tests\Security\WebAuthn\VirtualAuthenticator;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

class WebAuthnControllerTest extends IntegrationTestCase
{
    private User $user;
    private VirtualAuthenticator $authenticator;
    private MockClock $clock;
    private string $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set($this->clock = new MockClock('2026-11-05 12:00:00 UTC'));
        $this->user = self::createUser('keys', 'keys@example.org', roles: ['ROLE_ADMIN']);
        $this->user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->user, 'existing-password'));
        $this->store($this->user);
        $this->client->loginUser($this->user);
        $this->authenticator = new VirtualAuthenticator();
        $crawler = $this->client->request('GET', '/profile/security/keys');
        $this->csrf = $crawler->filter('form[data-webauthn="register"] input[name="_token"]')->attr('value');
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testWebAuthnEnrollmentIsDeniedWithoutAdminRole(): void
    {
        $user = $this->freshUser();
        $user->removeRole('ROLE_ADMIN');
        self::getEM()->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile/security/keys');
        self::assertResponseStatusCodeSame(404);

        foreach (['/profile/security/keys/options', '/profile/security/keys/register'] as $path) {
            $this->client->request('POST', $path, ['_token' => $this->csrf]);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testOverviewShowsEnabledPasskeysForNonAdminsWithKeys(): void
    {
        $this->register();
        $user = $this->freshUser();
        $user->removeRole('ROLE_ADMIN');
        self::getEM()->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/security');
        self::assertSelectorTextContains('[aria-labelledby="passkeys-heading"] .badge', 'Enabled');
        self::assertSelectorExists('.security-tabs a[href="/profile/security/keys"]');
        self::assertSame('/profile/security/keys', $crawler->selectLink('View passkeys and security keys')->attr('href'));
    }

    #[TestWith([false, 'ROLE_ADMIN'])]
    #[TestWith([true, 'ROLE_ADMIN'])]
    #[TestWith([false, 'ROLE_DISABLE_2FA'])]
    #[TestWith([true, 'ROLE_DISABLE_2FA'])]
    public function testSupportCanInspectAnotherUsersPasskeyStatus(bool $hasKeys, string $role): void
    {
        if ($hasKeys) {
            $this->register();
        }

        $viewer = self::createUser('support', 'support@example.org', roles: [$role]);
        $this->store($viewer);
        $this->client->loginUser($viewer);
        $this->client->request('GET', '/users/keys/totp/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[aria-labelledby="passkeys-heading"] .badge', $hasKeys ? 'Enabled' : 'Not set up');
        self::assertSelectorNotExists('[aria-labelledby="passkeys-heading"] a');
        self::assertSelectorNotExists('[aria-labelledby="passkeys-heading"] form');
    }

    public function testFirstKeyEnablesMfaAndShowsBackupCodeOnce(): void
    {
        $credential = $this->register();
        self::assertSame('My key', $credential->getName());
        self::assertFalse($this->freshUser()->isTotpAuthenticationEnabled());
        self::assertTrue($this->freshUser()->hasSecondFactorAuthenticationEnabled());
        $crawler = $this->client->request('GET', '/profile/security/keys');
        $backupCode = $crawler->filter('.two-factor-backup-code');
        self::assertCount(1, $backupCode);
        self::getEM()->clear();
        self::assertTrue($this->freshUser()->isBackupCode($backupCode->text()));
        self::assertStringContainsString('My key', $crawler->text());
        $this->client->request('GET', '/profile/security/keys');
        self::assertSelectorNotExists('.two-factor-backup-code');
        $audit = self::getEM()->getRepository(AuditRecord::class)->findOneBy(['type' => AuditLogEventType::WebAuthnCredentialChanged, 'userId' => $this->user->getId()]);
        self::assertNotNull($audit);
        self::assertSame('added', $audit->attributes['action']);
    }

    public function testEnrollmentAndVerificationKeepTheSameUserHandle(): void
    {
        $userHandle = $this->freshUser()->getWebAuthnUserHandle();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $userHandle);

        $credential = $this->register();
        self::assertNull($credential->getLastUsedAt());
        $this->authenticator->counter = 0xfffffffe;
        $this->verify();

        self::getEM()->clear();
        $storedCredential = self::getEM()->find(WebAuthnCredential::class, $credential->id);
        self::assertNotNull($storedCredential);
        self::assertNotNull($storedCredential->getLastUsedAt());
        self::assertSame($this->authenticator->counter, $storedCredential->toCredentialRecord()->counter);
        self::assertSame($userHandle, $this->freshUser()->getWebAuthnUserHandle());
    }

    public function testAssertionUsesTheLatestStoredCounter(): void
    {
        $credential = $this->register();
        $this->client->request('POST', '/profile/security/keys/verify/options', ['_token' => $this->csrf]);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);

        $dispatcher = self::getService(EventDispatcherInterface::class);
        $listener = static function () use ($credential): void {
            $em = self::getEM();
            $loadedCredential = $em->find(WebAuthnCredential::class, $credential->id);
            self::assertNotNull($loadedCredential);
            self::assertSame(0, $loadedCredential->toCredentialRecord()->counter);

            $em->getConnection()->update('webauthn_credential', ['counter' => 10], ['id' => $credential->id]);
        };
        $dispatcher->addListener(KernelEvents::CONTROLLER, $listener);

        try {
            $this->client->request('POST', '/profile/security/keys/verify', [
                '_token' => $this->csrf,
                'credential' => $this->authenticator->authenticate($options),
            ]);
            self::assertResponseStatusCodeSame(400);
        } finally {
            $dispatcher->removeListener(KernelEvents::CONTROLLER, $listener);
        }

        $storedCounter = self::getEM()->getConnection()->fetchOne('SELECT counter FROM webauthn_credential WHERE id = ?', [$credential->id]);
        self::assertSame(10, (int) $storedCounter);
    }

    public function testPasswordLoginAllowsFirstKeyEnrollmentWithoutAnExistingSecondFactor(): void
    {
        $this->client->restart();
        $this->client->request('POST', '/login/', ['_username' => 'keys', '_password' => 'existing-password']);
        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', '/profile/security/keys');
        self::assertResponseIsSuccessful();
        $this->csrf = $crawler->filter('form[data-webauthn="register"] input[name="_token"]')->attr('value');
        $this->register();
        self::assertTrue($this->freshUser()->hasSecondFactorAuthenticationEnabled());
    }

    public function testRevokedCredentialIdCannotBeRegisteredAgain(): void
    {
        $credential = $this->register();
        $this->verify();
        $this->client->request('POST', '/profile/security/keys/'.$credential->id.'/edit', ['_token' => $this->csrf, 'action' => 'remove']);
        self::assertResponseRedirects('/profile/security/keys');
        $this->client->request('POST', '/profile/security/keys/options', ['_token' => $this->csrf]);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $this->client->request('POST', '/profile/security/keys/register', ['_token' => $this->csrf, 'name' => 'Reused', 'credential' => $this->authenticator->register($options)]);
        self::assertResponseStatusCodeSame(400);
        self::assertCount(0, $this->freshUser()->getWebAuthnCredentials());
        self::assertNotNull(self::getEM()->find(WebAuthnCredential::class, $credential->id)->getRevokedAt());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testRemovingLastKeyKeepsTheBackupCodeOnlyIfTotpRemains(bool $withTotp): void
    {
        $credential = $this->register();
        $user = $this->freshUser();

        if ($withTotp) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        }

        $user->setBackupCode('keep1234');
        self::getEM()->flush();
        $this->client->loginUser($user);

        $this->verify();
        $crawler = $this->client->request('GET', '/profile/security/keys/'.$credential->id.'/remove');
        self::assertResponseIsSuccessful();

        if ($withTotp) {
            self::assertSelectorNotExists('.security-settings .text-danger');
            self::assertSelectorTextContains('.security-settings', 'Your other second factors and backup code will remain enabled.');
        } else {
            self::assertSelectorTextContains('.security-settings .text-danger', 'This is your last second factor.');
        }

        self::assertCount(1, $this->freshUser()->getWebAuthnCredentials());
        $this->client->submit($crawler->selectButton('Remove key')->form());
        self::assertResponseRedirects('/profile/security/keys');

        $user = $this->freshUser();
        self::getEM()->refresh($user);
        self::assertSame([], $user->getWebAuthnCredentials());
        self::assertSame($withTotp, $user->isBackupCode('keep1234'));
    }

    public function testAddingTotpToAnAccountWithAKeyKeepsTheBackupCode(): void
    {
        $this->register();
        $user = $this->freshUser();
        $user->setBackupCode('keep1234');
        self::getEM()->flush();

        $this->client->request('GET', '/users/keys/totp/enable');
        self::assertResponseRedirects();
        self::assertStringStartsWith('/profile/security/keys?return_to=', (string) $this->client->getResponse()->headers->get('Location'));
        $crawler = $this->client->followRedirect();
        $verificationForm = $crawler->filter('form[data-webauthn="verify"]')->form();
        self::assertSelectorTextContains('#confirm-key-changes', 'Confirm your identity to continue with your security change.');
        self::assertSelectorTextContains('.flash-container .alert-info', 'Confirm your identity with a passkey or security key before setting up an authenticator app.');
        self::assertFalse($this->freshUser()->isTotpAuthenticationEnabled());

        $this->client->request('POST', '/profile/security/keys/verify/options', ['_token' => $this->csrf]);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $this->client->request('POST', $verificationForm->getUri(), [
            '_token' => $this->csrf,
            'credential' => $this->authenticator->authenticate($options),
        ]);
        self::assertResponseIsSuccessful();
        $destination = json_decode($this->client->getResponse()->getContent(), true)['redirect'];
        self::assertStringStartsWith('/users/keys/totp/enable?_hash=', $destination);
        $crawler = $this->client->request('GET', $destination);
        $this->client->submit($crawler->selectButton('Enable authenticator app')->form(), [
            'enable_totp_auth[code]' => TotpAuthenticatorStub::MOCKED_VALID_CODE,
        ]);
        self::assertResponseRedirects('/profile/security/authenticator');

        self::getEM()->clear();
        self::assertTrue($this->freshUser()->isTotpAuthenticationEnabled());
        self::assertTrue($this->freshUser()->isBackupCode('keep1234'));
    }

    #[TestWith(['GET', '/profile/security/keys'])]
    #[TestWith(['POST', '/profile/security/keys/verify'])]
    public function testVerificationRejectsAnUnsignedReturnUrl(string $method, string $path): void
    {
        $credential = $this->register();
        $this->client->request($method, $path.'?'.http_build_query(['return_to' => 'https://attacker.test/']), ['_token' => $this->csrf]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/profile/security/keys/'.$credential->id.'/edit', ['_token' => $this->csrf, 'action' => 'remove']);
        self::assertResponseRedirects('/profile/security/keys');
        self::assertCount(1, $this->freshUser()->getWebAuthnCredentials());
    }

    public function testPasskeyConfirmationReturnsToTotpRemovalWithoutRemovingIt(): void
    {
        $this->register();
        $user = $this->freshUser();
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        self::getEM()->flush();
        $this->client->loginUser($user);
        $this->client->request('GET', '/users/keys/totp/disable');
        $crawler = $this->client->followRedirect();
        $crawler = $this->client->click($crawler->selectLink('Confirm with a passkey or security key instead')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#confirm-key-changes', 'Confirm your identity to continue with your security change.');
        $verificationForm = $crawler->filter('form[data-webauthn="verify"]')->form();

        $this->verify($verificationForm->getUri());
        $destination = json_decode($this->client->getResponse()->getContent(), true)['redirect'];
        self::assertStringStartsWith('/users/keys/totp/disable?_hash=', $destination);
        $crawler = $this->client->request('GET', $destination);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->freshUser()->isTotpAuthenticationEnabled());

        $this->client->submit($crawler->selectButton('Remove authenticator app')->form());
        self::assertResponseRedirects('/users/keys/totp/');
        self::assertFalse($this->freshUser()->isTotpAuthenticationEnabled());
        self::assertCount(1, $this->freshUser()->getWebAuthnCredentials());
    }

    public function testRemovingTotpKeepsKeysAndTheBackupCode(): void
    {
        $this->register();
        $user = $this->freshUser();
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setBackupCode('keep1234');
        self::getEM()->flush();
        $this->client->loginUser($user);
        $this->client->request('GET', '/users/keys/totp/disable');
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Confirm identity')->form(), ['verify_totp[code]' => TotpAuthenticatorStub::MOCKED_VALID_CODE]);
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Remove authenticator app')->form());
        self::assertResponseRedirects('/users/keys/totp/');
        self::assertFalse($this->freshUser()->isTotpAuthenticationEnabled());
        self::assertTrue($this->freshUser()->hasSecondFactorAuthenticationEnabled());
        self::assertTrue($this->freshUser()->isBackupCode('keep1234'));
        $this->beginLogin();
        $this->finishLoginWithKey();
        $this->client->request('GET', '/profile/security/keys');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-webauthn="register"]');
    }

    public function testAddingAKeyAfterTotpVerificationKeepsTheBackupCode(): void
    {
        $user = $this->freshUser();
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setBackupCode('keep1234');
        self::getEM()->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/security/keys');
        $crawler = $this->client->click($crawler->selectLink('Confirm with authenticator app')->link());
        $crawler = $this->client->submit($crawler->selectButton('Confirm identity')->form(), ['verify_totp[code]' => '123456']);
        self::assertResponseStatusCodeSame(422);

        $this->client->submit($crawler->selectButton('Confirm identity')->form(), ['verify_totp[code]' => TotpAuthenticatorStub::MOCKED_VALID_CODE]);
        self::assertResponseRedirects();
        self::assertStringStartsWith('/profile/security/keys?_hash=', (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->followRedirect();
        self::assertSelectorExists('form[data-webauthn="register"]');

        $this->register();
        self::getEM()->clear();
        $storedUser = $this->freshUser();
        self::assertCount(1, $storedUser->getWebAuthnCredentials());
        self::assertTrue($storedUser->isBackupCode('keep1234'));

        $this->client->request('GET', '/profile/security/keys');
        self::assertSelectorNotExists('.two-factor-backup-code');
    }

    public function testRememberedSessionCanEnrollAFirstKey(): void
    {
        $session = $this->client->getSession();
        self::assertNotNull($session);
        $session->set('_security_main', serialize(new RememberMeToken($this->user, 'main')));
        $session->save();

        $this->register();
        self::assertTrue($this->freshUser()->hasSecondFactorAuthenticationEnabled());
    }

    #[TestWith(['/profile/security/keys/options'])]
    #[TestWith(['/profile/security/keys/register'])]
    public function testExistingSecondFactorRequiresRecentProofToEnrollAKey(string $path): void
    {
        $user = $this->freshUser();
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        self::getEM()->flush();
        $this->client->loginUser($user);

        $this->client->request('POST', $path, ['_token' => $this->csrf]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testStepUpAllowsMultipleKeysAndIndividualRenameAndRemoval(): void
    {
        $first = $this->register();
        $this->verify();
        $this->authenticator = new VirtualAuthenticator();
        $second = $this->register('Spare key');
        self::assertCount(2, $this->freshUser()->getWebAuthnCredentials());
        $this->verify();
        $this->client->request('POST', '/profile/security/keys/'.$first->id.'/edit', ['_token' => $this->csrf, 'name' => 'Renamed key', 'action' => 'rename']);
        self::assertResponseRedirects('/profile/security/keys');
        self::assertSame('Renamed key', self::getEM()->find(WebAuthnCredential::class, $first->id)->getName());
        $this->client->request('POST', '/profile/security/keys/'.$first->id.'/edit', ['_token' => $this->csrf, 'action' => 'remove']);
        self::assertResponseRedirects('/profile/security/keys');
        $active = $this->freshUser()->getWebAuthnCredentials();
        self::assertCount(1, $active);
        self::assertSame($second->id, $active[0]->id);
        self::assertNotNull(self::getEM()->find(WebAuthnCredential::class, $first->id)->getRevokedAt());
    }

    public function testRemovalReviewRequiresProofAndReturnsAfterConfirmationWithoutRemovingTheKey(): void
    {
        $credential = $this->register();
        $crawler = $this->client->request('GET', '/profile/security/keys');
        self::assertSame('/profile/security/keys/'.$credential->id.'/remove', $crawler->selectLink('Remove')->attr('href'));
        $this->client->click($crawler->selectLink('Remove')->link());
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#confirm-key-changes', 'Confirm your identity to continue with your security change.');
        $verificationForm = $crawler->filter('form[data-webauthn="verify"]')->form();

        $this->verify($verificationForm->getUri());
        $destination = json_decode($this->client->getResponse()->getContent(), true)['redirect'];
        self::assertStringStartsWith('/profile/security/keys/'.$credential->id.'/remove?_hash=', $destination);
        $crawler = $this->client->request('GET', $destination);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->freshUser()->getWebAuthnCredentials());
        self::assertSame('/profile/security/keys', $crawler->selectLink('Keep key')->attr('href'));
    }

    public function testRemovalReviewCannotShowAnotherAccountsKey(): void
    {
        $credential = $this->register();
        $other = self::createUser('other', 'other@example.org');
        $this->store($other);
        $this->client->loginUser($other);
        $this->client->request('GET', '/profile/security/keys/'.$credential->id.'/remove');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestWith(['remove'])]
    #[TestWith(['rename'])]
    public function testExpiredProofReturnsToConfirmationWithoutRetryingTheChange(string $action): void
    {
        $credential = $this->register();
        $this->verify();
        $this->clock->sleep(SecondFactorProofStore::RECENT_AUTHENTICATION_LIFETIME);
        $this->client->request('POST', '/profile/security/keys/'.$credential->id.'/edit', [
            '_token' => $this->csrf, 'action' => $action, 'name' => 'Changed name',
        ]);
        self::assertResponseRedirects('/profile/security/keys');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-container .alert-info', 'Confirm your identity again before changing your keys. Your change has not been applied.');
        $verificationForm = $crawler->filter('form[data-webauthn="verify"]')->form();

        $this->verify($verificationForm->getUri());
        $destination = json_decode($this->client->getResponse()->getContent(), true)['redirect'];
        self::assertStringStartsWith('/profile/security/keys?_hash=', $destination);
        $this->client->request('GET', $destination);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->freshUser()->getWebAuthnCredentials());
        self::assertSame('My key', $this->freshUser()->getWebAuthnCredentials()[0]->getName());
    }

    public function testExpiredProofDoesNotTurnAnInvalidRemovalCsrfTokenIntoARedirect(): void
    {
        $credential = $this->register();
        $this->verify();
        $this->clock->sleep(SecondFactorProofStore::RECENT_AUTHENTICATION_LIFETIME);
        $this->client->request('POST', '/profile/security/keys/'.$credential->id.'/edit', ['_token' => 'invalid', 'action' => 'remove']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->freshUser()->getWebAuthnCredentials());
    }

    #[TestWith(['/profile/security/keys/options'])]
    #[TestWith(['/profile/security/keys/register'])]
    #[TestWith(['/profile/security/keys/verify/options'])]
    #[TestWith(['/profile/security/keys/verify'])]
    public function testCsrfIsRequired(string $path): void
    {
        $this->client->request('POST', $path, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->freshUser()->getWebAuthnCredentials());
    }

    public function testVerificationOptionsWithoutARegisteredKeyFailCleanly(): void
    {
        $this->client->request('POST', '/profile/security/keys/verify/options', ['_token' => $this->csrf]);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('No passkey or security key is registered.', $this->client->getResponse()->getContent());
    }

    public function testAUserCannotEditAnotherUsersCredential(): void
    {
        $credential = $this->register();
        $other = self::createUser('other', 'other@example.org', roles: ['ROLE_ADMIN']);
        $this->store($other);
        $this->client->loginUser($other);
        $crawler = $this->client->request('GET', '/profile/security/keys');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/profile/security/keys/'.$credential->id.'/edit', ['_token' => $token, 'action' => 'remove']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testKeyOnlyLoginFinishesMfaAndRecordsRecentWebAuthnProof(): void
    {
        $this->register();
        $this->freshUser()->removeRole('ROLE_ADMIN');
        self::getEM()->flush();

        $this->beginLogin();
        $this->finishLoginWithKey();
        $crawler = $this->client->request('GET', '/profile/security/keys');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.security-tabs a[href="/profile/security/keys"]');
        self::assertSelectorNotExists('form[data-webauthn="register"]');
        $crawler = $this->client->click($crawler->selectLink('Remove')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.text-danger', 'This is your last second factor.');
        self::assertCount(1, $this->freshUser()->getWebAuthnCredentials());
        $this->client->submit($crawler->selectButton('Remove key')->form());
        self::assertResponseRedirects('/profile/security');
        self::assertSame([], $this->freshUser()->getWebAuthnCredentials());
        $this->client->request('GET', '/users/keys/totp/disable');
        self::assertResponseRedirects('/users/keys/totp/');
    }

    public function testBothMethodsOfferSwitchingAndWebAuthnProofAllowsTotpRemoval(): void
    {
        $this->register();
        $this->freshUser()->setTotpSecret('JBSWY3DPEHPK3PXP');
        self::getEM()->flush();
        $crawler = $this->beginLogin();
        $crawler = $this->client->click($crawler->selectLink('Use authenticator app instead')->link());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[id="_auth_code"]'));
        $this->client->click($crawler->selectLink('Use passkey or security key instead')->link());
        $this->finishLoginWithKey();
        $this->client->request('GET', '/profile/security/keys');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-webauthn="register"]');
        $crawler = $this->client->request('GET', '/users/keys/totp/disable');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Remove authenticator app')->form());
        self::assertResponseRedirects('/users/keys/totp/');
        self::assertFalse($this->freshUser()->isTotpAuthenticationEnabled());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testBackupCodeDisablesKeysWithoutCreditingWebAuthnProof(bool $withTotp): void
    {
        $this->register();
        $this->freshUser()->removeRole('ROLE_ADMIN');
        if ($withTotp) {
            $this->freshUser()->setTotpSecret('JBSWY3DPEHPK3PXP');
        }
        $this->freshUser()->setBackupCode('abc12345');
        self::getEM()->flush();
        $crawler = $this->beginLogin();
        $this->client->submit($crawler->selectButton('Use backup code')->form(), ['_auth_code' => 'abc12345']);
        self::assertResponseRedirects();
        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage(), 'Subject', 'Two-factor authentication disabled after backup code use on Packagist.org');
        self::assertCount(0, $this->freshUser()->getWebAuthnCredentials());
        self::assertFalse($this->freshUser()->hasSecondFactorAuthenticationEnabled());
        self::assertFalse($this->freshUser()->isBackupCode('abc12345'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testSupportResetRevokesKeysAndClearsTheBackupCode(bool $withTotp): void
    {
        $this->register();
        $user = $this->freshUser();

        if ($withTotp) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        }

        $user->setBackupCode('abc12345');
        $admin = self::createUser('resetadmin', 'resetadmin@example.org', roles: ['ROLE_DISABLE_2FA']);
        $this->store($admin);
        $crawler = $this->beginLogin();
        $crawler = $this->client->click($crawler->selectLink('Request a reset')->link());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Request a reset')->form());
        self::assertResponseIsSuccessful();

        $request = self::getService(SupportRequestRepository::class)->findOpen($this->freshUser(), SupportRequestType::LostTotp);
        self::assertNotNull($request);
        $path = '/admin/support/'.$request->publicId;
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', $path);
        self::assertSelectorTextContains('body', 'Passkey or security key added');
        $this->client->submit($crawler->selectButton('Remove all second factors and resolve')->form());
        self::assertResponseRedirects($path);

        self::getEM()->clear();
        self::assertSame(SupportRequestStatus::Resolved, self::getService(SupportRequestRepository::class)->findOneByPublicId($request->publicId)?->status);
        $user = $this->freshUser();
        self::assertFalse($user->hasSecondFactorAuthenticationEnabled());
        self::assertFalse($user->isBackupCode('abc12345'));
        $audit = self::getEM()->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditLogEventType::WebAuthnCredentialChanged->value,
            'userId' => $user->getId(),
            'actorId' => $admin->getId(),
        ]);
        self::assertNotNull($audit);
        self::assertSame('removed', $audit->attributes['action']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testExpiredOrReplacedChallengeIsRejected(bool $replace): void
    {
        $this->register();
        $path = '/profile/security/keys/verify/options';
        $this->client->request('POST', $path, ['_token' => $this->csrf]);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);

        if ($replace) {
            $this->client->request('POST', $path, ['_token' => $this->csrf]);
            self::assertResponseIsSuccessful();
        } else {
            $this->clock->sleep(120);
        }

        $this->client->request('POST', '/profile/security/keys/verify', [
            '_token' => $this->csrf,
            'credential' => $this->authenticator->authenticate($options),
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('This passkey request expired or was replaced. Start again.', $this->client->getResponse()->getContent());
    }

    public function testExpiredLoginChallengeShowsRetryMessage(): void
    {
        $this->register();
        $this->beginLogin();
        $this->client->request('POST', '/2fa/webauthn/options', ['_token' => $this->csrf]);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $this->clock->sleep(120);

        $crawler = $this->client->request('GET', '/2fa');
        $this->client->submit($crawler->filter('form[data-webauthn="login"]')->form(), [
            '_auth_code' => $this->authenticator->authenticate($options),
        ]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'This passkey request expired or was replaced. Start again.');
    }

    public function testRecoveryRejectsABackupCodeChangedAfterLoadingTheAccount(): void
    {
        $this->register();
        $user = $this->freshUser();
        $user->setBackupCode('old12345');
        $em = self::getEM();
        $em->flush();
        $em->getConnection()->update('fos_user', ['backupCode' => 'new12345'], ['id' => $user->getId()]);

        $manager = self::getService(BackupCodeManager::class);
        self::assertTrue($manager->isBackupCode($user, 'old12345'));
        $emailCount = count(self::getMailerMessages());

        try {
            $manager->invalidateBackupCode($user, 'old12345');
            self::fail('Expected stale backup code to be rejected');
        } catch (BadCredentialsException) {
            self::assertTrue($user->isBackupCode('new12345'));
            self::assertCount(1, $user->getWebAuthnCredentials());
            self::assertEmailCount($emailCount);
        }
    }

    public function testEnrollmentRejectsAnAccountChangedAfterAuthorization(): void
    {
        $user = $this->freshUser();
        $connection = self::getEM()->getConnection();
        $connection->update('fos_user', [
            'totpSecret' => 'JBSWY3DPEHPK3PXP',
            'backupCode' => 'keep1234',
            'sessionBuster' => $user->getSessionBuster() + 1,
        ], ['id' => $user->getId()]);
        self::assertFalse($user->hasSecondFactorAuthenticationEnabled());

        try {
            self::getService(TotpAuthManager::class)->enableTotpAuthentication($user, $user, 'AAAAAAAAAAAAAAAA');
            self::fail('Expected enrollment against stale account state to be rejected');
        } catch (BadCredentialsException) {
            $stored = $connection->fetchAssociative('SELECT totpSecret, backupCode FROM fos_user WHERE id = ?', [$user->getId()]);
            self::assertSame(['totpSecret' => 'JBSWY3DPEHPK3PXP', 'backupCode' => 'keep1234'], $stored);
        }
    }

    public function testBackupCodeRecoveryRollsBackAllChangesOnFailure(): void
    {
        $credential = $this->register();
        $user = $this->freshUser();
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setBackupCode('abc12345');
        $em = self::getEM();
        $em->flush();
        $connection = $em->getConnection();
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);

        $failure = new \RuntimeException('Recovery failed');
        $listener = static function (SecondFactorsResetEvent $event) use ($em, $failure): void {
            self::assertFalse($event->user->hasSecondFactorAuthenticationEnabled());
            self::assertFalse($event->user->isBackupCode('abc12345'));
            $em->flush();

            throw $failure;
        };
        $dispatcher->addListener(SecondFactorsResetEvent::class, $listener, -100);

        $emailCount = count(self::getMailerMessages());

        try {
            self::getService(BackupCodeManager::class)->invalidateBackupCode($user, 'abc12345');
            self::fail('Expected recovery to fail');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        } finally {
            $dispatcher->removeListener(SecondFactorsResetEvent::class, $listener);
        }

        self::assertEmailCount($emailCount);

        $stored = $connection->fetchAssociative('SELECT totpSecret, backupCode FROM fos_user WHERE id = ?', [$user->getId()]);
        self::assertSame(['totpSecret' => 'JBSWY3DPEHPK3PXP', 'backupCode' => 'abc12345'], $stored);
        self::assertNull($connection->fetchOne('SELECT revokedAt FROM webauthn_credential WHERE id = ?', [$credential->id]));
    }

    public function testPasswordResetDoesNotBypassKeyOnlyMfa(): void
    {
        $this->register();
        $user = $this->freshUser();
        $user->initializeConfirmationToken();
        $user->setPasswordRequestedAt(new \DateTimeImmutable());
        self::getEM()->flush();
        $this->client->restart();
        $crawler = $this->client->request('GET', '/reset-password/reset/'.$user->getConfirmationToken());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Reset password')->form(), ['reset_password_form[plainPassword]' => 'replacement-password']);
        self::assertResponseRedirects();
        $this->client->request('GET', '/profile/security/keys');
        self::assertResponseRedirects('/2fa');
        $crawler = $this->client->request('GET', '/2fa');
        $this->csrf = $crawler->filter('form[data-webauthn="login"] input[name="_token"]')->attr('value');
        $this->finishLoginWithKey();
        $this->client->request('GET', '/profile/security/keys');
        self::assertResponseIsSuccessful();
    }

    public function testLoginChallengeCannotBeReplayed(): void
    {
        $this->register();
        $this->beginLogin();
        $this->client->request('POST', '/2fa/webauthn/options', ['_token' => $this->csrf]);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $response = $this->authenticator->authenticate($options);
        $crawler = $this->client->request('GET', '/2fa');
        $form = $crawler->filter('form[data-webauthn="login"]')->form();
        $this->client->submit($form, ['_auth_code' => $response]);
        self::assertResponseRedirects();
        $this->beginLogin();
        $this->client->submit($form, ['_auth_code' => $response]);
        self::assertResponseRedirects('/2fa');
        $this->client->request('GET', '/profile/security/keys');
        self::assertResponseRedirects('/2fa');
    }

    private function register(string $name = 'My key'): WebAuthnCredential
    {
        $this->client->request('POST', '/profile/security/keys/options', ['_token' => $this->csrf]);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('none', $options['attestation']);
        $this->client->request('POST', '/profile/security/keys/register', [
            '_token' => $this->csrf, 'name' => $name, 'credential' => $this->authenticator->register($options),
        ]);
        self::assertResponseIsSuccessful();

        return self::getEM()->find(WebAuthnCredential::class, hash('sha256', $this->authenticator->id));
    }

    private function verify(string $url = '/profile/security/keys/verify'): void
    {
        $this->authenticator->counter++;
        $this->client->request('POST', '/profile/security/keys/verify/options', ['_token' => $this->csrf]);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $this->client->request('POST', $url, ['_token' => $this->csrf, 'credential' => $this->authenticator->authenticate($options)]);
        self::assertResponseIsSuccessful();
    }

    private function beginLogin(): \Symfony\Component\DomCrawler\Crawler
    {
        $this->client->restart();
        $this->client->request('POST', '/login/', ['_username' => 'keys', '_password' => 'existing-password']);
        $crawler = $this->client->request('GET', '/2fa', ['preferProvider' => 'webauthn']);
        self::assertResponseIsSuccessful();
        $this->csrf = $crawler->filter('form[data-webauthn="login"] input[name="_token"]')->attr('value');

        return $crawler;
    }

    private function finishLoginWithKey(): void
    {
        $this->client->request('POST', '/2fa/webauthn/options', ['_token' => $this->csrf]);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $crawler = $this->client->request('GET', '/2fa');
        $this->client->submit($crawler->filter('form[data-webauthn="login"]')->form(), ['_auth_code' => $this->authenticator->authenticate($options)]);
        self::assertResponseRedirects();
    }

    private function freshUser(): User
    {
        return self::getEM()->find(User::class, $this->user->getId());
    }
}
