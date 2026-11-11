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

namespace App\Tests\Security;

use App\Entity\User;
use App\Entity\WebAuthnCredential;
use App\Security\SecondFactorProofStore;
use App\Security\WebAuthn\CredentialType;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\NilUuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\CertificateTrustPath;

class SecondFactorProofStoreTest extends TestCase
{
    private User $user;
    private MockClock $clock;
    private RequestStack $requests;
    private Session $session;
    private SecondFactorProofStore $secondFactorProofStore;

    protected function setUp(): void
    {
        $this->user = new User();
        new \ReflectionProperty(User::class, 'id')->setValue($this->user, 123);
        $this->user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $this->clock = new MockClock('2026-10-28 12:00:00 UTC');
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        $this->requests = new RequestStack();
        $this->requests->push($request);
        $this->secondFactorProofStore = new SecondFactorProofStore($this->requests, $this->clock);
    }

    public function testEnabledMfaDoesNotMeanRecentlyVerified(): void
    {
        self::assertTrue($this->user->hasSecondFactorAuthenticationEnabled());
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
    }

    public function testEvidenceExpiresExactlyAtTenMinutes(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        self::assertTrue($this->secondFactorProofStore->hasRecentProof($this->user));
        $this->clock->sleep(599);
        self::assertTrue($this->secondFactorProofStore->hasRecentProof($this->user));
        $this->clock->sleep(1);
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
    }

    public function testExpirationDoesNotEraseAuthenticationEvidence(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->clock->sleep(600);
        self::assertTrue($this->secondFactorProofStore->hasProof($this->user));
        self::assertTrue($this->secondFactorProofStore->hasProof($this->user, 'totp'));
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user, 'totp'));
    }

    public function testMethodsKeepIndependentTimestamps(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->clock->sleep(600);
        $this->secondFactorProofStore->record($this->user, 'another_factor');
        self::assertTrue($this->secondFactorProofStore->hasProof($this->user, 'totp'));
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user, 'totp'));
        self::assertTrue($this->secondFactorProofStore->hasRecentProof($this->user, 'another_factor'));
        self::assertTrue($this->secondFactorProofStore->hasRecentProof($this->user));
        self::assertFalse($this->secondFactorProofStore->hasProof($this->user, 'unused_factor'));
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user, 'unused_factor'));
    }

    public function testVerifyingTheSameMethodRefreshesItsTimestamp(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->clock->sleep(600);
        $this->secondFactorProofStore->record($this->user, 'totp');
        self::assertTrue($this->secondFactorProofStore->hasRecentProof($this->user, 'totp'));
    }

    public function testEvidenceDoesNotCarryAcrossUsersOrSessions(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        $other = clone $this->user;
        new \ReflectionProperty(User::class, 'id')->setValue($other, 456);
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($other));
        $this->requests->getCurrentRequest()->setSession(new Session(new MockArraySessionStorage()));
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
    }

    public function testInvalidatingSessionsRevokesEvidence(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->user->invalidateAllSessions();
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
    }

    public function testRemovingAuthenticatorRevokesEvidence(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->user->setTotpSecret(null);
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
        $this->secondFactorProofStore->record($this->user, 'totp');
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
    }

    #[TestWith([CredentialType::Regular])]
    #[TestWith([CredentialType::VerifiedHardware])]
    public function testWebAuthnProofUsesTheVerifiedCredentialType(CredentialType $type): void
    {
        $credential = $this->credential($type);
        $this->secondFactorProofStore->recordWebAuthn($credential);
        self::assertTrue($this->secondFactorProofStore->hasRecentProof($this->user, SecondFactorProofStore::WEBAUTHN));
        self::assertSame($type === CredentialType::VerifiedHardware, $this->secondFactorProofStore->hasRecentProof($this->user, SecondFactorProofStore::HARDWARE_KEY));
        self::assertFalse($this->secondFactorProofStore->hasProof($this->user, SecondFactorProofStore::TOTP));
        $this->clock->sleep(600);
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user, SecondFactorProofStore::HARDWARE_KEY));
        self::assertSame($type === CredentialType::VerifiedHardware, $this->secondFactorProofStore->hasProof($this->user, SecondFactorProofStore::HARDWARE_KEY));
    }

    public function testRegularKeyCannotRefreshHardwareProof(): void
    {
        $this->secondFactorProofStore->recordWebAuthn($this->credential(CredentialType::VerifiedHardware));
        $this->clock->sleep(600);
        $this->secondFactorProofStore->recordWebAuthn($this->credential(CredentialType::Regular));
        self::assertTrue($this->secondFactorProofStore->hasRecentProof($this->user, SecondFactorProofStore::WEBAUTHN));
        self::assertTrue($this->secondFactorProofStore->hasProof($this->user, SecondFactorProofStore::HARDWARE_KEY));
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user, SecondFactorProofStore::HARDWARE_KEY));
    }

    public function testRevokedKeyCannotGrantProof(): void
    {
        $credential = $this->credential(CredentialType::VerifiedHardware);
        $credential->revoke();
        $this->secondFactorProofStore->recordWebAuthn($credential);
        self::assertFalse($this->secondFactorProofStore->hasProof($this->user));
    }

    public function testCredentialProofsExpireIndependentlyAtTenMinutes(): void
    {
        $first = $this->credential(CredentialType::VerifiedHardware, 'first');
        $second = $this->credential(CredentialType::Regular, 'second');
        $this->secondFactorProofStore->recordWebAuthn($first);
        self::assertSame(SecondFactorProofStore::RECENT_AUTHENTICATION_LIFETIME, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($first));
        self::assertSame(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($second));
        $this->clock->sleep(599);
        self::assertSame(1, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($first));
        $this->secondFactorProofStore->recordWebAuthn($second);
        self::assertGreaterThan(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($first));
        $this->clock->sleep(1);
        self::assertSame(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($first));
        self::assertGreaterThan(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($second));
        $this->secondFactorProofStore->record($this->user, SecondFactorProofStore::TOTP);
        self::assertSame(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($first));
        $this->secondFactorProofStore->recordWebAuthn($first);
        self::assertGreaterThan(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($first));
        $this->secondFactorProofStore->clear();
        self::assertSame(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($first));
        self::assertSame(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($second));
    }

    public function testGeneralWebAuthnProofDoesNotIdentifyACredential(): void
    {
        $credential = $this->credential(CredentialType::Regular);
        $this->secondFactorProofStore->record($this->user, SecondFactorProofStore::WEBAUTHN);
        self::assertSame(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($credential));
    }

    public function testCredentialProofCannotCarryAcrossAccountsOrSessions(): void
    {
        $credential = $this->credential(CredentialType::Regular);
        $this->secondFactorProofStore->recordWebAuthn($credential);
        $other = clone $this->user;
        new \ReflectionProperty(User::class, 'id')->setValue($other, 456);
        $otherCredential = new WebAuthnCredential($other, $credential->toCredentialRecord());
        self::assertSame(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($otherCredential));
        $this->requests->getCurrentRequest()->setSession(new Session(new MockArraySessionStorage()));
        self::assertSame(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($credential));
        $this->secondFactorProofStore->recordWebAuthn($credential);
        $this->user->invalidateAllSessions();
        self::assertSame(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($credential));
    }

    public function testCredentialProofRejectsRevocationAndFutureTimestamps(): void
    {
        $credential = $this->credential(CredentialType::Regular);
        $this->secondFactorProofStore->recordWebAuthn($credential);
        $this->clock->modify('-1 second');
        self::assertSame(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($credential));
        $this->clock->sleep(1);
        self::assertGreaterThan(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($credential));
        $credential->revoke();
        self::assertSame(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($credential));
    }

    public function testCredentialProofSurvivesSessionSerialization(): void
    {
        $credential = $this->credential(CredentialType::Regular);
        $this->secondFactorProofStore->recordWebAuthn($credential);
        $key = 'security.second_factor_authentication';
        $this->session->set($key, unserialize(serialize($this->session->get($key))));
        self::assertGreaterThan(0, $this->secondFactorProofStore->getCredentialProofRemainingLifetime($credential));
    }

    private function credential(CredentialType $type, string $id = 'credential'): WebAuthnCredential
    {
        $record = new CredentialRecord($id, 'public-key', [], 'basic', new CertificateTrustPath(['certificate']), new NilUuid(), 'public-key', 'user', 0);
        $credential = new WebAuthnCredential($this->user, $record);

        if ($type === CredentialType::VerifiedHardware) {
            $credential->recordAttestation($type, 'Hardware key');
        }

        return $credential;
    }

    public function testFutureEvidenceIsRejected(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->clock->modify('-1 second');
        self::assertFalse($this->secondFactorProofStore->hasProof($this->user));
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
    }

    public function testOldArrayFormatIsRejected(): void
    {
        $this->session->set('security.second_factor_authentication', [
            'user' => $this->user->getId(),
            'version' => $this->user->getSessionBuster(),
            'methods' => ['totp' => $this->clock->now()->getTimestamp()],
        ]);
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
    }

    public function testClearingEvidenceAndRequestsWithoutSessionsAreSafe(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->secondFactorProofStore->clear();
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
        self::assertFalse($this->secondFactorProofStore->hasProof($this->user));
        $this->requests->push(new Request());
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->secondFactorProofStore->clear();
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
    }
}
