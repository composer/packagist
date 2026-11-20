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
use App\Security\SecondFactorManager;
use App\Security\SecondFactorProofStore;
use App\Security\TotpAuthManager;
use App\Security\WebAuthn\CredentialType;
use App\Security\WebAuthn\FidoMetadataRepository;
use App\Security\WebAuthn\FidoMetadataUnavailableException;
use App\Security\WebAuthn\WebAuthnManager;
use App\Support\SupportRequestStatus;
use App\Support\SupportRequestType;
use App\Tests\IntegrationTestCase;
use App\Tests\Mock\TotpAuthenticatorStub;
use App\Tests\Security\WebAuthn\AttestationAuthority;
use App\Tests\Security\WebAuthn\VirtualAuthenticator;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Webauthn\MetadataService\Statement\StatusReport;

class WebAuthnControllerTest extends IntegrationTestCase
{
    private User $user;
    private VirtualAuthenticator $authenticator;
    private MockClock $clock;
    private string $csrf;
    private FidoMetadataRepository $metadata;
    private string $metadataFailure = '';
    /** @var list<string> */
    private array $metadataStatuses = [];

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set($this->clock = new MockClock('2026-11-05 12:00:00 UTC'));
        $this->user = self::createUser('keys', 'keys@example.org', roles: ['ROLE_ADMIN']);
        $this->user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->user, 'existing-password'));
        $this->store($this->user);
        $this->client->loginUser($this->user);
        $this->authenticator = new VirtualAuthenticator();
        $this->metadata = $this->createStub(FidoMetadataRepository::class);
        self::getContainer()->set(FidoMetadataRepository::class, $this->metadata);
        $crawler = $this->client->request('GET', '/profile/security/keys');
        $this->csrf = $crawler->filter('form[data-webauthn="register"] input[name="_token"]')->attr('value');
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    #[TestWith(['regular'])]
    #[TestWith(['hardware'])]
    public function testWebAuthnEnrollmentIsDeniedWithoutAdminRole(string $kind): void
    {
        $user = $this->freshUser();
        $user->removeRole('ROLE_ADMIN');
        self::getEM()->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile/security/keys');
        self::assertResponseStatusCodeSame(404);

        foreach (['/profile/security/keys/options', '/profile/security/keys/register'] as $path) {
            $this->client->request('POST', $path, ['_token' => $this->csrf, 'name' => 'My key', 'kind' => $kind]);
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

    public function testUnsupportedCredentialTypeIsRejected(): void
    {
        $this->client->request('POST', '/profile/security/keys/options', ['_token' => $this->csrf, 'name' => 'My key', 'kind' => 'unknown']);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Choose a supported key type.', $this->client->getResponse()->getContent());
    }

    public function testUnavailableMetadataBlocksOnlyHardwareEnrollmentOptions(): void
    {
        $this->metadata->method('ensureAvailable')->willThrowException(new FidoMetadataUnavailableException('The FIDO metadata feed has not been imported'));
        $this->client->request('POST', '/profile/security/keys/options', ['_token' => $this->csrf, 'name' => 'My key', 'kind' => 'hardware']);
        self::assertResponseStatusCodeSame(503);
        self::assertJsonStringEqualsJsonString('{"error":"Verified hardware security key enrollment is temporarily unavailable. Please try again later."}', $this->client->getResponse()->getContent());

        $this->client->request('POST', '/profile/security/keys/options', ['_token' => $this->csrf, 'name' => 'My key', 'kind' => 'regular']);
        self::assertResponseIsSuccessful();
    }

    public function testMetadataCanExpireAfterHardwareEnrollmentOptionsAreIssued(): void
    {
        $authority = new AttestationAuthority();
        $this->authenticator->attestation = $authority;
        $this->authenticator->aaguid = AttestationAuthority::AAGUID;
        $this->metadata->method('findOneByAAGUID')->willThrowException(new FidoMetadataUnavailableException('The FIDO metadata feed has expired'));

        $this->client->request('POST', '/profile/security/keys/options', ['_token' => $this->csrf, 'name' => 'My key', 'kind' => 'hardware']);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $this->client->request('POST', '/profile/security/keys/register', [
            '_token' => $this->csrf, 'name' => 'My key',
            'credential' => $this->authenticator->register($options),
        ]);
        self::assertResponseStatusCodeSame(503);
        self::assertJsonStringEqualsJsonString('{"error":"Verified hardware security key enrollment is temporarily unavailable. Please try again later."}', $this->client->getResponse()->getContent());
        self::assertFalse($this->freshUser()->hasSecondFactorAuthenticationEnabled());
    }

    #[TestWith([''])]
    #[TestWith(['remote'])]
    public function testVerifiedHardwareKeyEnrollmentAndSignIn(string $variant): void
    {
        $this->configureHardwareMetadata($variant);
        $this->client->request('POST', '/profile/security/keys/options', ['_token' => $this->csrf, 'name' => 'My key', 'kind' => 'hardware']);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('direct', $options['attestation']);
        self::assertSame('cross-platform', $options['authenticatorSelection']['authenticatorAttachment']);

        $this->client->request('POST', '/profile/security/keys/register', [
            '_token' => $this->csrf, 'name' => 'Hardware key', 'kind' => 'regular',
            'credential' => $this->authenticator->register($options),
        ]);
        self::assertResponseIsSuccessful();
        self::getEM()->clear();
        $credential = self::getEM()->find(WebAuthnCredential::class, hash('sha256', $this->authenticator->id));
        self::assertNotNull($credential);
        self::assertSame('Test hardware key', $credential->authenticatorDescription);
        self::assertSame(CredentialType::VerifiedHardware, $credential->credentialType);
        $audit = self::getEM()->getRepository(AuditRecord::class)->findOneBy(['type' => AuditLogEventType::WebAuthnCredentialAdded, 'userId' => $this->user->getId()]);
        self::assertNotNull($audit);
        self::assertSame('hardware', $audit->attributes['credentialType']);
        self::assertSame('Test hardware key', $audit->attributes['authenticatorDescription']);
        $this->client->request('GET', '/profile/security/keys');
        self::assertSelectorTextContains('.badge', 'Verified hardware security key');
        self::assertSelectorExists('.two-factor-backup-code');
        $this->beginLogin();
        $this->finishLoginWithKey();
        $this->client->request('GET', '/profile/security/keys');
        self::assertResponseIsSuccessful();
    }

    #[TestWith(['none'])]
    #[TestWith(['self'])]
    #[TestWith(['unknown'])]
    #[TestWith(['software'])]
    #[TestWith(['backup'])]
    #[TestWith(['nil'])]
    #[TestWith(['no_status'])]
    #[TestWith(['revoked'])]
    #[TestWith(['compromised'])]
    #[TestWith(['untrusted'])]
    #[TestWith(['platform'])]
    #[TestWith(['missing_attachment'])]
    #[TestWith(['mixed_attachment'])]
    public function testHardwareEnrollmentRejectsUnverifiedKeys(string $failure): void
    {
        $this->configureHardwareMetadata($failure);
        $this->client->request('POST', '/profile/security/keys/options', ['_token' => $this->csrf, 'name' => 'My key', 'kind' => 'hardware']);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $this->client->request('POST', '/profile/security/keys/register', [
            '_token' => $this->csrf, 'name' => 'Hardware key', 'kind' => 'regular',
            'credential' => $this->authenticator->register($options, $failure === 'self'),
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->freshUser()->hasSecondFactorAuthenticationEnabled());
        self::assertSame(0, self::getEM()->getRepository(WebAuthnCredential::class)->count([]));
        self::assertEmailCount(0);
    }

    #[TestWith([['REVOKED', 'FIDO_CERTIFIED']])]
    #[TestWith([['FIDO_CERTIFIED', 'REVOKED']])]
    #[TestWith([['ATTESTATION_KEY_COMPROMISE', 'FIDO_CERTIFIED']])]
    #[TestWith([['FIDO_CERTIFIED', 'ATTESTATION_KEY_COMPROMISE']])]
    public function testHardwareEnrollmentRejectsUnsafeStatusReportsInEitherOrder(array $statuses): void
    {
        $this->configureHardwareMetadata('', $statuses);
        $this->client->request('POST', '/profile/security/keys/options', ['_token' => $this->csrf, 'name' => 'My key', 'kind' => 'hardware']);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $this->client->request('POST', '/profile/security/keys/register', [
            '_token' => $this->csrf, 'name' => 'Hardware key',
            'credential' => $this->authenticator->register($options),
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->freshUser()->hasSecondFactorAuthenticationEnabled());
        self::assertSame(0, self::getEM()->getRepository(WebAuthnCredential::class)->count([]));
        self::assertEmailCount(0);
    }

    public function testOrdinaryEnrollmentCannotAcquireHardwareClassificationByChangingTheForm(): void
    {
        $this->client->request('POST', '/profile/security/keys/options', ['_token' => $this->csrf, 'name' => 'My key']);
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $this->client->request('POST', '/profile/security/keys/register', [
            '_token' => $this->csrf, 'kind' => 'hardware', 'name' => 'Ordinary key',
            'credential' => $this->authenticator->register($options),
        ]);
        self::assertResponseIsSuccessful();
        $credential = self::getEM()->find(WebAuthnCredential::class, hash('sha256', $this->authenticator->id));
        self::assertNull($credential->authenticatorDescription);
        self::assertSame(CredentialType::Regular, $credential->credentialType);
    }

    public function testHardwareEnrollmentCannotCrossAccounts(): void
    {
        $this->configureHardwareMetadata();
        $this->client->request('POST', '/profile/security/keys/options', ['_token' => $this->csrf, 'name' => 'My key', 'kind' => 'hardware']);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $other = self::createUser('other', 'other@example.org', roles: ['ROLE_ADMIN']);
        $this->store($other);
        $this->client->loginUser($other);
        $crawler = $this->client->request('GET', '/profile/security/keys');
        $csrf = $crawler->filter('form[data-webauthn="register"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/profile/security/keys/register', [
            '_token' => $csrf, 'name' => 'Wrong account', 'credential' => $this->authenticator->register($options),
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertFalse($other->hasSecondFactorAuthenticationEnabled());
        self::assertFalse($this->freshUser()->hasSecondFactorAuthenticationEnabled());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testAuthenticationProofReflectsTheKeyUsedForSignInAndConfirmation(bool $hardware): void
    {
        if ($hardware) {
            $this->configureHardwareMetadata();
        }

        $this->register('My key', $hardware);
        $this->client->request('GET', '/profile/security/keys');
        self::assertSelectorNotExists('dl time');
        self::assertSelectorNotExists('[data-credential-verification]');
        $this->assertWebAuthnProof(true, false);
        $this->verify();
        $this->client->request('GET', '/profile/security/keys');
        self::assertSelectorTextContains('dl time', ' UTC');
        self::assertSelectorTextContains('[data-credential-verification] .badge', 'Verified in this session · 10 min remaining');
        $this->assertWebAuthnProof(true, $hardware);
        $this->clock->sleep(599);
        $this->client->request('GET', '/profile/security/keys');
        self::assertSelectorTextContains('[data-credential-verification] .badge', 'less than a minute remaining');
        $this->clock->sleep(1);
        $this->client->request('GET', '/profile/security/keys');
        self::assertSelectorTextContains('dl time', ' UTC');
        self::assertSelectorNotExists('[data-credential-verification]');
        $this->assertWebAuthnProof(true, $hardware, false);
        $this->beginLogin();
        $this->assertWebAuthnProof(false, false);
        $this->authenticator->counter++;
        $this->finishLoginWithKey();
        $this->client->request('GET', '/profile/security/keys');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-credential-verification] .badge', 'Verified in this session · 10 min remaining');
        $this->assertWebAuthnProof(true, $hardware);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testFailedHardwareAssertionDoesNotGrantProof(bool $signIn): void
    {
        $this->configureHardwareMetadata();
        $this->register('Hardware key', true);

        if ($signIn) {
            $this->beginLogin();
            $this->authenticator->corruptSignature = true;
            $this->finishLoginWithKey();
            self::assertResponseRedirects('/2fa');
            $this->assertWebAuthnProof(false, false);

            return;
        }

        $this->clock->sleep(600);
        $this->client->request('POST', '/profile/security/keys/verify/options', ['_token' => $this->csrf]);
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $this->authenticator->corruptSignature = true;
        $this->client->request('POST', '/profile/security/keys/verify', [
            '_token' => $this->csrf, 'credential' => $this->authenticator->authenticate($options),
        ]);
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/profile/security/keys');
        self::assertSelectorNotExists('[data-credential-verification]');
        $this->assertWebAuthnProof(true, false, false);
    }

    public function testRecentVerificationIndicatorIdentifiesOnlyTheKeyUsed(): void
    {
        $first = $this->register('First key');
        $firstAuthenticator = $this->authenticator;
        $this->authenticator = new VirtualAuthenticator();
        $second = $this->register('Second key');
        $this->authenticator = $firstAuthenticator;
        $this->verify();
        $crawler = $this->client->request('GET', '/profile/security/keys');
        self::assertSelectorCount(1, '[data-credential-verification]');
        $firstRow = $crawler->filter('#key-'.$first->id)->closest('li');
        $secondRow = $crawler->filter('#key-'.$second->id)->closest('li');
        self::assertNotNull($firstRow);
        self::assertNotNull($secondRow);
        self::assertCount(1, $firstRow->filter('[data-credential-verification]'));
        self::assertCount(0, $secondRow->filter('[data-credential-verification]'));
    }

    public function testLastUseInAnotherSessionDoesNotShowTheIndicator(): void
    {
        $credential = $this->register();
        $this->verify();
        $this->client->request('GET', '/profile/security/keys');
        self::assertSelectorExists('[data-credential-verification]');
        $this->client->restart();
        $this->client->loginUser($this->freshUser());
        $this->client->request('GET', '/profile/security/keys');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-credential-verification]');
        $storedCredential = self::getEM()->find(WebAuthnCredential::class, $credential->id);
        self::assertNotNull($storedCredential);
        self::assertNotNull($storedCredential->lastUsedAt);
    }

    private function assertWebAuthnProof(bool $authenticated, bool $hardware, bool $recent = true): void
    {
        $request = new Request();
        $request->setSession($this->client->getSession());
        $requests = new RequestStack();
        $requests->push($request);
        $proofs = new SecondFactorProofStore($requests, $this->clock);
        $user = $this->freshUser();
        self::assertSame($authenticated, $proofs->hasProof($user, SecondFactorProofStore::WEBAUTHN));
        self::assertSame($authenticated && $recent, $proofs->hasRecentProof($user, SecondFactorProofStore::WEBAUTHN));
        self::assertSame($hardware, $proofs->hasProof($user, SecondFactorProofStore::HARDWARE_KEY));
        self::assertSame($hardware && $recent, $proofs->hasRecentProof($user, SecondFactorProofStore::HARDWARE_KEY));
    }

    /** @param list<string>|null $statuses */
    private function configureHardwareMetadata(string $failure = '', ?array $statuses = null): void
    {
        Clock::set($this->clock = new MockClock());
        $authority = new AttestationAuthority();
        $statement = $authority->statement();
        $this->authenticator->attestation = \in_array($failure, ['none', 'self'], true) ? null : $authority;
        $this->authenticator->aaguid = $failure === 'nil' ? '00000000-0000-0000-0000-000000000000' : $authority::AAGUID;

        if ($failure === 'backup') {
            $this->authenticator->flags |= 8;
        }

        if (\in_array($failure, ['software', 'remote'], true)) {
            $statement->keyProtection = $failure === 'software' ? ['software'] : ['hardware', 'remote_handle'];
        }

        $statement->attachmentHint = match ($failure) {
            'platform' => ['internal'],
            'missing_attachment' => [],
            'mixed_attachment' => ['internal', 'external', 'wired'],
            default => $statement->attachmentHint,
        };

        if ($failure === 'untrusted') {
            $statement = new AttestationAuthority()->statement();
        }

        $status = match ($failure) {
            'revoked' => 'REVOKED', 'compromised' => 'ATTESTATION_KEY_COMPROMISE', default => 'FIDO_CERTIFIED',
        };
        $repository = $this->metadata;
        $repository->method('findOneByAAGUID')->willReturnCallback(function () use ($failure, $statement) {
            if ($this->metadataFailure === 'unavailable') {
                throw new FidoMetadataUnavailableException('The FIDO metadata feed has expired');
            }

            return $failure === 'unknown' || $this->metadataFailure === 'missing' ? null : $statement;
        });
        $this->metadataStatuses = $statuses ?? ($failure === 'no_status' ? [] : [$status]);
        $repository->method('findStatusReportsByAAGUID')->willReturnCallback(fn () => array_map(static fn (string $status) => new StatusReport($status, null, null, null, null, null, null, null), $this->metadataStatuses));
    }

    #[TestWith(['REVOKED', false])]
    #[TestWith(['REVOKED', true])]
    #[TestWith(['USER_KEY_REMOTE_COMPROMISE', false])]
    #[TestWith(['USER_KEY_REMOTE_COMPROMISE', true])]
    #[TestWith(['USER_KEY_PHYSICAL_COMPROMISE', false])]
    #[TestWith(['ATTESTATION_KEY_COMPROMISE', true])]
    #[TestWith(['USER_VERIFICATION_BYPASS', false])]
    #[TestWith(['missing', false])]
    #[TestWith(['missing', true])]
    #[TestWith(['no_status', false])]
    #[TestWith(['software', true])]
    public function testCurrentMetadataDisablesAnEnrolledHardwareKey(string $failure, bool $signIn): void
    {
        $this->configureHardwareMetadata();
        $this->authenticator->counter = 0;
        $credential = $this->register('Hardware key', true);
        $this->assertWebAuthnProof(true, false);
        $this->freshUser()->setBackupCode('keep1234');
        self::getEM()->flush();
        $version = $this->freshUser()->getSessionBuster();
        $lastUsedAt = $credential->lastUsedAt;

        if ($signIn) {
            $this->beginLogin();
        }

        $optionsPath = $signIn ? '/2fa/webauthn/options' : '/profile/security/keys/verify/options';
        $this->client->request('POST', $optionsPath, ['_token' => $this->csrf]);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);

        if ($failure === 'missing') {
            $this->metadataFailure = 'missing';
        } elseif ($failure === 'software') {
            $this->metadata->findOneByAAGUID(AttestationAuthority::AAGUID)->keyProtection = ['software'];
        } else {
            $this->metadataStatuses = $failure === 'no_status' ? [] : ['FIDO_CERTIFIED', $failure, 'FIDO_CERTIFIED_L1'];
        }

        $response = $this->authenticator->authenticate($options);

        if ($signIn) {
            $crawler = $this->client->request('GET', '/2fa');
            $this->client->submit($crawler->filter('form[data-webauthn="login"]')->form(), ['_auth_code' => $response]);
            self::assertResponseRedirects('/2fa');
        } else {
            $this->client->request('GET', '/profile/security/keys');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.text-bg-danger', 'Hardware security key no longer trusted');
            self::assertSelectorNotExists('.text-bg-success');
            $this->client->request('POST', '/profile/security/keys/verify', ['_token' => $this->csrf, 'credential' => $response]);
            self::assertResponseStatusCodeSame(400);
        }

        self::getEM()->clear();
        $user = $this->freshUser();
        $stored = $user->findWebAuthnCredential($credential->id);
        self::assertNotNull($stored);
        self::assertNotNull($stored->disabledAt);
        self::assertNull($stored->revokedAt);
        self::assertEquals($lastUsedAt, $stored->lastUsedAt);
        self::assertTrue($user->hasSecondFactorAuthenticationEnabled());
        self::assertTrue($user->isBackupCode('keep1234'));
        self::assertSame($version + 1, $user->getSessionBuster());
        $this->assertWebAuthnProof(false, false);
        $audits = self::getEM()->getRepository(AuditRecord::class)->findBy(['type' => AuditLogEventType::WebAuthnCredentialDisabled, 'userId' => $user->getId()]);
        self::assertCount(1, $audits);
        self::assertSame('automation', $audits[0]->attributes['actor']);
        self::assertNotEmpty($audits[0]->attributes['reason']);
        $this->client->loginUser($user);
        $this->client->request('GET', '/profile/security/keys');
        self::assertSelectorTextContains('.text-bg-danger', 'Hardware security key disabled');
        self::assertSelectorNotExists('.text-bg-success');
        $this->client->request('POST', '/profile/security/keys/verify/options', ['_token' => $this->csrf]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(1, self::getEM()->getRepository(AuditRecord::class)->count(['type' => AuditLogEventType::WebAuthnCredentialDisabled, 'userId' => $user->getId()]));
        $this->beginLogin();
        $this->client->request('POST', '/2fa/webauthn/options', ['_token' => $this->csrf]);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('no longer trusted', $this->client->getResponse()->getContent());

        if ($failure === 'REVOKED' && !$signIn) {
            $crawler = $this->client->request('GET', '/2fa');
            $this->client->submit($crawler->selectButton('Use backup code')->form(), ['_auth_code' => 'keep1234']);
            self::assertResponseRedirects();
            self::assertFalse($this->freshUser()->hasSecondFactorAuthenticationEnabled());
            self::assertFalse($this->freshUser()->isBackupCode('keep1234'));
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testMetadataOutageTemporarilyBlocksHardwareAssertions(bool $signIn): void
    {
        $this->configureHardwareMetadata();
        $credential = $this->register('Hardware key', true);
        $version = $this->freshUser()->getSessionBuster();
        $this->metadataFailure = 'unavailable';
        $this->client->request('GET', '/profile/security/keys');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.text-bg-warning', 'Hardware verification temporarily unavailable');
        self::assertSelectorNotExists('.text-bg-success');

        if ($signIn) {
            $this->beginLogin();
        }

        $this->client->request('POST', $signIn ? '/2fa/webauthn/options' : '/profile/security/keys/verify/options', ['_token' => $this->csrf]);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $response = $this->authenticator->authenticate($options);

        if ($signIn) {
            $crawler = $this->client->request('GET', '/2fa');
            $this->client->submit($crawler->filter('form[data-webauthn="login"]')->form(), ['_auth_code' => $response]);
            self::assertResponseRedirects('/2fa');
            $this->client->followRedirect();
            self::assertSelectorTextContains('.alert-danger', 'Hardware security key verification is temporarily unavailable');
        } else {
            $this->client->request('POST', '/profile/security/keys/verify', ['_token' => $this->csrf, 'credential' => $response]);
            self::assertResponseStatusCodeSame(503);
            self::assertResponseHeaderSame('Retry-After', '60');
        }

        self::getEM()->clear();
        $stored = $this->freshUser()->findWebAuthnCredential($credential->id);
        self::assertNotNull($stored);
        self::assertNull($stored->disabledAt);
        self::assertNull($stored->lastUsedAt);
        self::assertSame($version, $this->freshUser()->getSessionBuster());
        self::assertSame(0, self::getEM()->getRepository(AuditRecord::class)->count(['type' => AuditLogEventType::WebAuthnCredentialDisabled]));
        $this->assertWebAuthnProof(!$signIn, false);
        $this->metadataFailure = '';

        if ($signIn) {
            $this->finishLoginWithKey();
        } else {
            $this->verify();
        }

        $this->assertWebAuthnProof(true, true);
    }

    public function testRegularKeyDoesNotRequireMetadataDuringAuthentication(): void
    {
        $this->register();
        $this->metadata->method('findOneByAAGUID')->willThrowException(new FidoMetadataUnavailableException('Unavailable'));
        $this->verify();
        $this->assertWebAuthnProof(true, false);
    }

    public function testInvalidSignatureCannotDisableAKeyWithRejectedMetadata(): void
    {
        $this->configureHardwareMetadata();
        $credential = $this->register('Hardware key', true);
        $version = $this->freshUser()->getSessionBuster();
        $this->metadataStatuses = ['REVOKED'];
        $this->authenticator->corruptSignature = true;
        $this->client->request('POST', '/profile/security/keys/verify/options', ['_token' => $this->csrf]);
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $this->client->request('POST', '/profile/security/keys/verify', [
            '_token' => $this->csrf, 'credential' => $this->authenticator->authenticate($options),
        ]);
        self::assertResponseStatusCodeSame(400);
        self::getEM()->clear();
        self::assertNull(self::getEM()->find(WebAuthnCredential::class, $credential->id)->disabledAt);
        self::assertSame($version, $this->freshUser()->getSessionBuster());
        self::assertSame(0, self::getEM()->getRepository(AuditRecord::class)->count(['type' => AuditLogEventType::WebAuthnCredentialDisabled]));
    }

    public function testFirstKeyEnablesMfaAndShowsBackupCodeOnce(): void
    {
        $credential = $this->register();
        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage(), 'Subject', 'Passkey or security key added on Packagist.org');
        self::assertEmailTextBodyContains(self::getMailerMessage(), 'A passkey or security key was added to your Packagist.org account.');

        self::assertSame('My key', $credential->name);
        self::assertFalse($this->freshUser()->isTotpAuthenticationEnabled());
        self::assertTrue($this->freshUser()->hasSecondFactorAuthenticationEnabled());
        $crawler = $this->client->request('GET', '/profile/security/keys');
        self::assertSelectorExists('form[data-webauthn="register"]');
        self::assertSelectorNotExists('form[data-webauthn="verify"]');
        $backupCode = $crawler->filter('.two-factor-backup-code');
        self::assertCount(1, $backupCode);
        self::getEM()->clear();
        self::assertTrue($this->freshUser()->isBackupCode($backupCode->text()));
        self::assertSame('My key', $crawler->filter('#key-'.$credential->id)->attr('value'));
        $this->client->request('GET', '/profile/security/keys');
        self::assertSelectorNotExists('.two-factor-backup-code');
        $audit = self::getEM()->getRepository(AuditRecord::class)->findOneBy(['type' => AuditLogEventType::WebAuthnCredentialAdded, 'userId' => $this->user->getId()]);
        self::assertNotNull($audit);
        self::assertSame('My key', $audit->attributes['credentialName']);
        self::assertSame('regular', $audit->attributes['credentialType']);
        self::assertNull($audit->attributes['authenticatorDescription']);
    }

    public function testNewlyEnrolledKeyCanBeRenamedAndAnotherAddedWithoutConfirmation(): void
    {
        $credential = $this->register();
        $this->client->request('POST', '/profile/security/keys/'.$credential->id.'/edit', [
            '_token' => $this->csrf, 'action' => 'rename', 'name' => 'Renamed key',
        ]);
        self::assertResponseRedirects('/profile/security/keys');
        self::assertSame('Renamed key', $this->freshUser()->findWebAuthnCredential($credential->id)?->name);
        self::assertEmailCount(0);
        $renames = self::getEM()->getRepository(AuditRecord::class)->findBy(['type' => AuditLogEventType::WebAuthnCredentialRenamed, 'userId' => $this->user->getId()]);
        self::assertCount(1, $renames);
        self::assertSame('Renamed key', $renames[0]->attributes['credentialName']);
        self::assertSame('My key', $renames[0]->attributes['previousCredentialName']);

        $auditor = self::createUser('auditor', 'auditor@example.org', roles: ['ROLE_AUDITOR']);
        $this->store($auditor);
        $this->client->loginUser($auditor);
        $this->client->request('GET', '/admin/audit-log?type[]=webauthn_credential_added&type[]=webauthn_credential_renamed');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('td.audit-log-details', 'My key → Renamed key');
        self::assertStringContainsString('My key', $this->client->getResponse()->getContent());
        $this->client->loginUser($this->freshUser());

        $this->authenticator = new VirtualAuthenticator();
        $this->register('Spare key');
        self::assertCount(2, $this->freshUser()->getWebAuthnCredentials());
    }

    public function testRenameAuditsTheLatestStoredName(): void
    {
        $credential = $this->register();
        $em = self::getEM();
        $em->getConnection()->update('webauthn_credential', ['name' => 'Changed in another request'], ['id' => $credential->id]);
        self::assertSame('My key', $credential->name);

        self::getService(WebAuthnManager::class)->rename($credential, 'Renamed key');

        $audit = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditLogEventType::WebAuthnCredentialRenamed,
            'userId' => $this->user->getId(),
        ]);
        self::assertNotNull($audit);
        self::assertSame('Changed in another request', $audit->attributes['previousCredentialName']);
        self::assertSame('Renamed key', $audit->attributes['credentialName']);
    }

    #[TestWith(['My key'])]
    #[TestWith(['  My key  '])]
    public function testSavingTheSameNameDoesNotRecordARename(string $name): void
    {
        $credential = $this->register();
        $this->client->request('POST', '/profile/security/keys/'.$credential->id.'/edit', [
            '_token' => $this->csrf, 'action' => 'rename', 'name' => $name,
        ]);
        self::assertResponseRedirects('/profile/security/keys');
        self::assertSame('My key', $this->freshUser()->findWebAuthnCredential($credential->id)?->name);
        self::assertSame(0, self::getEM()->getRepository(AuditRecord::class)->count([
            'type' => AuditLogEventType::WebAuthnCredentialRenamed,
            'userId' => $this->user->getId(),
        ]));
        self::assertEmailCount(0);
    }

    public function testEnrollmentAndVerificationKeepTheSameUserHandle(): void
    {
        $userHandle = $this->freshUser()->getWebAuthnUserHandle();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $userHandle);

        $credential = $this->register();
        self::assertNull($credential->lastUsedAt);
        $this->authenticator->counter = 0xFFFFFFFE;
        $this->verify();

        self::getEM()->clear();
        $storedCredential = self::getEM()->find(WebAuthnCredential::class, $credential->id);
        self::assertNotNull($storedCredential);
        self::assertNotNull($storedCredential->lastUsedAt);
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

    public function testRegistrationOptionsRejectAWhitespaceOnlyName(): void
    {
        $this->client->request('POST', '/profile/security/keys/options', ['_token' => $this->csrf, 'name' => '   ']);

        self::assertResponseStatusCodeSame(400);
        self::assertJsonStringEqualsJsonString(
            '{"error":"Enter a name between 1 and 100 characters."}',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testConcurrentAccountChangeShowsAnErrorInsteadOfRemovingTheKey(): void
    {
        $credential = $this->register();
        $user = $this->freshUser();
        $userId = $user->getId();
        $sessionBuster = $user->getSessionBuster();
        $dispatcher = self::getService(EventDispatcherInterface::class);
        $listener = static function () use ($userId, $sessionBuster): void {
            self::getEM()->getConnection()->update('fos_user', ['sessionBuster' => $sessionBuster + 1], ['id' => $userId]);
        };
        $dispatcher->addListener(KernelEvents::CONTROLLER, $listener);

        try {
            $this->client->request('POST', '/profile/security/keys/'.$credential->id.'/edit', [
                '_token' => $this->csrf, 'action' => 'remove',
            ]);
            self::assertResponseRedirects('/profile/security/keys');
        } finally {
            $dispatcher->removeListener(KernelEvents::CONTROLLER, $listener);
        }

        $session = $this->client->getSession();
        self::assertNotNull($session);
        self::assertSame(['Account security settings have changed. Please sign in again.'], $session->getFlashBag()->peek('error'));
        self::assertEmailCount(0);
        self::assertNull(self::getEM()->getConnection()->fetchOne('SELECT revokedAt FROM webauthn_credential WHERE id = ?', [$credential->id]));
        $this->client->followRedirect();
        self::assertResponseRedirects('/login/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-container', 'Account security settings have changed. Please sign in again.');
    }

    public function testRevokedCredentialIdCannotBeRegisteredAgain(): void
    {
        $credential = $this->register();
        $this->verify();
        $this->client->request('POST', '/profile/security/keys/'.$credential->id.'/edit', ['_token' => $this->csrf, 'action' => 'remove']);
        self::assertResponseRedirects('/profile/security/keys');
        $this->client->request('POST', '/profile/security/keys/options', ['_token' => $this->csrf, 'name' => 'Reused']);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $this->client->request('POST', '/profile/security/keys/register', ['_token' => $this->csrf, 'name' => 'Reused', 'credential' => $this->authenticator->register($options)]);
        self::assertResponseStatusCodeSame(400);
        self::assertCount(0, $this->freshUser()->getWebAuthnCredentials());
        self::assertNotNull(self::getEM()->find(WebAuthnCredential::class, $credential->id)->revokedAt);
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
        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage(), 'Subject', 'Passkey or security key removed on Packagist.org');
        self::assertEmailTextBodyContains(self::getMailerMessage(), 'A passkey or security key was removed from your Packagist.org account.');

        $user = $this->freshUser();
        self::getEM()->refresh($user);
        self::assertSame([], $user->getWebAuthnCredentials());
        self::assertSame($withTotp, $user->isBackupCode('keep1234'));
    }

    public function testAddingTotpToAnAccountWithAKeyKeepsTheBackupCode(): void
    {
        $this->register();
        $this->clock->sleep(SecondFactorProofStore::RECENT_AUTHENTICATION_LIFETIME);
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
        $this->clock->sleep(SecondFactorProofStore::RECENT_AUTHENTICATION_LIFETIME);
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

    #[TestWith([false])]
    #[TestWith([true])]
    public function testAddingAKeyAfterTotpVerificationKeepsTheBackupCode(bool $hardware): void
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

        if ($hardware) {
            $this->configureHardwareMetadata();
        }

        $this->register('My key', $hardware);
        self::getEM()->clear();
        $storedUser = $this->freshUser();
        self::assertCount(1, $storedUser->getWebAuthnCredentials());
        self::assertTrue($storedUser->isBackupCode('keep1234'));

        $this->client->request('GET', '/profile/security/keys');
        self::assertSelectorNotExists('.two-factor-backup-code');
        self::assertSelectorNotExists('form[data-webauthn="verify"]');
        self::assertSelectorExists('form[data-webauthn="register"]');

        $this->authenticator = new VirtualAuthenticator();
        $this->register('Spare key');
        self::assertCount(2, $this->freshUser()->getWebAuthnCredentials());
        self::assertTrue($this->freshUser()->isBackupCode('keep1234'));
    }

    #[TestWith(['/profile/security/keys/options', 'regular'])]
    #[TestWith(['/profile/security/keys/register', 'regular'])]
    #[TestWith(['/profile/security/keys/options', 'hardware'])]
    #[TestWith(['/profile/security/keys/register', 'hardware'])]
    public function testRememberedSessionCannotEnrollAFirstKey(string $path, string $kind): void
    {
        if ($kind === 'hardware') {
            $this->configureHardwareMetadata();
        }

        $this->client->restart();
        $this->client->request('POST', '/login/', [
            '_username' => 'keys', '_password' => 'existing-password', '_remember_me' => 'on',
        ]);
        self::assertNotNull($this->client->getCookieJar()->get('pauth'));
        $crawler = $this->client->request('GET', '/profile/security/keys');
        $this->csrf = $crawler->filter('form[data-webauthn="register"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/profile/security/keys/options', [
            '_token' => $this->csrf, 'name' => 'My key', 'kind' => $kind,
        ]);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $credential = $this->authenticator->register($options);
        $session = $this->client->getSession();
        self::assertNotNull($session);
        $session->remove('_security_main');
        $session->save();

        $this->client->request('GET', '/profile/security/keys');
        self::assertResponseIsSuccessful();
        self::assertInstanceOf(RememberMeToken::class, self::getContainer()->get('security.token_storage')->getToken());
        self::assertSelectorNotExists('form[data-webauthn="register"]');
        self::assertSelectorTextContains('a[href="/login/"]', 'Sign in again to add your first key');
        $this->client->request('POST', $path, [
            '_token' => $this->csrf, 'name' => 'My key', 'kind' => $kind, 'credential' => $credential,
        ]);
        self::assertResponseRedirects('/login/');
        self::getEM()->clear();
        self::assertFalse($this->freshUser()->hasSecondFactorAuthenticationEnabled());
        self::assertCount(0, self::getEM()->getRepository(WebAuthnCredential::class)->findBy(['user' => $this->freshUser()]));
        self::assertEmailCount(0);
    }

    #[TestWith(['/profile/security/keys/options', 'regular'])]
    #[TestWith(['/profile/security/keys/register', 'regular'])]
    #[TestWith(['/profile/security/keys/options', 'hardware'])]
    #[TestWith(['/profile/security/keys/register', 'hardware'])]
    public function testExistingSecondFactorRequiresRecentProofToEnrollAKey(string $path, string $kind): void
    {
        $user = $this->freshUser();
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        self::getEM()->flush();
        $this->client->loginUser($user);

        $this->client->request('POST', $path, ['_token' => $this->csrf, 'name' => 'My key', 'kind' => $kind]);
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
        self::assertSame('Renamed key', self::getEM()->find(WebAuthnCredential::class, $first->id)->name);
        $this->client->request('POST', '/profile/security/keys/'.$first->id.'/edit', ['_token' => $this->csrf, 'action' => 'remove']);
        self::assertResponseRedirects('/profile/security/keys');
        $active = $this->freshUser()->getWebAuthnCredentials();
        self::assertCount(1, $active);
        self::assertSame($second->id, $active[0]->id);
        self::assertNotNull(self::getEM()->find(WebAuthnCredential::class, $first->id)->revokedAt);
    }

    public function testRemovalReviewRequiresProofAndReturnsAfterConfirmationWithoutRemovingTheKey(): void
    {
        $credential = $this->register();
        $this->clock->sleep(SecondFactorProofStore::RECENT_AUTHENTICATION_LIFETIME);
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
        self::assertSame('My key', $this->freshUser()->getWebAuthnCredentials()[0]->name);
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

    #[TestWith(['/profile/security/keys/options', 'regular'])]
    #[TestWith(['/profile/security/keys/register', 'regular'])]
    #[TestWith(['/profile/security/keys/options', 'hardware'])]
    #[TestWith(['/profile/security/keys/register', 'hardware'])]
    #[TestWith(['/profile/security/keys/verify/options', 'regular'])]
    #[TestWith(['/profile/security/keys/verify', 'regular'])]
    public function testCsrfIsRequired(string $path, string $kind): void
    {
        $this->client->request('POST', $path, ['_token' => 'invalid', 'kind' => $kind]);
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
    #[TestWith([false, true])]
    #[TestWith([true])]
    public function testBackupCodeDisablesKeysWithoutCreditingWebAuthnProof(bool $withTotp, bool $hardware = false): void
    {
        if ($hardware) {
            $this->configureHardwareMetadata();
        }

        $this->register('My key', $hardware);
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
        self::assertEmailTextBodyContains(self::getMailerMessage(), 'Username: keys');
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
        self::assertSelectorTextContains('.alert-danger', 'a passkey or security key was added, in the last 7 days.');
        self::assertSelectorTextContains('body', 'Last passkey or security key added');
        $this->client->submit($crawler->selectButton('Remove all second factors and resolve')->form());
        self::assertResponseRedirects($path);

        self::getEM()->clear();
        self::assertSame(SupportRequestStatus::Resolved, self::getService(SupportRequestRepository::class)->findOneByPublicId($request->publicId)?->status);
        $user = $this->freshUser();
        self::assertFalse($user->hasSecondFactorAuthenticationEnabled());
        self::assertFalse($user->isBackupCode('abc12345'));
        $audit = self::getEM()->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditLogEventType::WebAuthnCredentialDeleted->value,
            'userId' => $user->getId(),
            'actorId' => $admin->getId(),
        ]);
        self::assertNotNull($audit);
        self::assertSame(AuditLogEventType::WebAuthnCredentialDeleted, $audit->type);
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

        $manager = self::getService(SecondFactorManager::class);
        self::assertTrue($manager->isBackupCode($user, 'old12345'));
        $emailCount = \count(self::getMailerMessages());

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

        $emailCount = \count(self::getMailerMessages());

        try {
            self::getService(SecondFactorManager::class)->invalidateBackupCode($user, 'abc12345');
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

    #[TestWith(['backup'])]
    #[TestWith(['support'])]
    #[TestWith(['removal'])]
    #[TestWith(['sessions'])]
    public function testTrustedCookieIsRevokedAfterRecoveryAndReenrollment(string $action): void
    {
        $credential = $this->register();
        $this->freshUser()->setBackupCode('abc12345');
        self::getEM()->flush();
        $this->beginLogin();
        $this->finishLoginWithKey(true);
        $trusted = $this->client->getCookieJar()->get('trusted_device');
        self::assertNotNull($trusted);

        $this->client->restart();
        $this->client->getCookieJar()->set($trusted);
        $this->client->request('POST', '/login/', ['_username' => 'keys', '_password' => 'existing-password']);
        $this->client->request('GET', '/profile/security');
        self::assertResponseIsSuccessful();

        $user = $this->freshUser();
        $manager = self::getService(SecondFactorManager::class);

        if ($action === 'backup') {
            $manager->invalidateBackupCode($user, 'abc12345');
        } elseif ($action === 'support') {
            self::getEM()->wrapInTransaction(static function () use ($manager, $user): void {
                $manager->stageSecondFactorReset($user, $user, 'Support reset');
            });
        } elseif ($action === 'removal') {
            $credential = $user->findWebAuthnCredential($credential->id);
            self::assertNotNull($credential);
            self::getService(WebAuthnManager::class)->revoke($credential);
        } else {
            $user->invalidateAllSessions();
            self::getEM()->flush();
        }

        if ($action !== 'sessions') {
            self::assertFalse($user->hasSecondFactorAuthenticationEnabled());
            $this->client->loginUser($user);
            $crawler = $this->client->request('GET', '/profile/security/keys');
            $this->csrf = $crawler->filter('form[data-webauthn="register"] input[name="_token"]')->attr('value');
            $this->authenticator = new VirtualAuthenticator();
            $this->register('Replacement key');
        }

        $this->client->restart();
        $this->client->getCookieJar()->set($trusted);
        $this->client->request('POST', '/login/', ['_username' => 'keys', '_password' => 'existing-password']);
        $this->client->request('GET', '/profile/security');
        self::assertResponseRedirects('/2fa');
        $this->client->followRedirect();
        self::assertSelectorExists('form[data-webauthn="login"]');
    }

    private function register(string $name = 'My key', bool $hardware = false): WebAuthnCredential
    {
        $this->client->request('POST', '/profile/security/keys/options', ['_token' => $this->csrf, 'name' => $name, 'kind' => $hardware ? 'hardware' : 'regular']);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($hardware ? 'direct' : 'none', $options['attestation']);
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

    private function beginLogin(): Crawler
    {
        $this->client->restart();
        $this->client->request('POST', '/login/', ['_username' => 'keys', '_password' => 'existing-password']);
        $crawler = $this->client->request('GET', '/2fa', ['preferProvider' => 'webauthn']);
        self::assertResponseIsSuccessful();
        $this->csrf = $crawler->filter('form[data-webauthn="login"] input[name="_token"]')->attr('value');

        return $crawler;
    }

    private function finishLoginWithKey(bool $trusted = false): void
    {
        $this->client->request('POST', '/2fa/webauthn/options', ['_token' => $this->csrf]);
        self::assertResponseIsSuccessful();
        $options = json_decode($this->client->getResponse()->getContent(), true);
        $crawler = $this->client->request('GET', '/2fa');
        $parameters = ['_auth_code' => $this->authenticator->authenticate($options)];

        if ($trusted) {
            $parameters['_trusted'] = 'on';
        }

        $this->client->submit($crawler->filter('form[data-webauthn="login"]')->form(), $parameters);
        self::assertResponseRedirects();
    }

    private function freshUser(): User
    {
        return self::getEM()->find(User::class, $this->user->getId());
    }
}
