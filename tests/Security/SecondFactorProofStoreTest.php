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
use App\Security\SecondFactorProofStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

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
