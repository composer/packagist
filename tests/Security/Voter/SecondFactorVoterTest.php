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

namespace App\Tests\Security\Voter;

use App\Entity\User;
use App\Security\SecondFactorProofStore;
use App\Security\Voter\SecondFactorVoter;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorToken;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;

class SecondFactorVoterTest extends TestCase
{
    private User $user;
    private MockClock $clock;
    private TokenStorage $tokens;
    private SecondFactorProofStore $secondFactorProofStore;
    private AuthorizationChecker $authorization;

    protected function setUp(): void
    {
        $this->user = new User();
        new \ReflectionProperty(User::class, 'id')->setValue($this->user, 123);
        $this->user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $this->clock = new MockClock();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requests = new RequestStack();
        $requests->push($request);
        $this->secondFactorProofStore = new SecondFactorProofStore($requests, $this->clock);
        $this->tokens = new TokenStorage();
        $this->tokens->setToken(new UsernamePasswordToken($this->user, 'main', ['ROLE_USER']));
        $this->authorization = new AuthorizationChecker($this->tokens, new AccessDecisionManager([
            new SecondFactorVoter($this->secondFactorProofStore),
        ]));
    }

    public function testEnrollmentAndPrimaryLoginDoNotCountAsSecondFactorAuthentication(): void
    {
        self::assertTrue($this->user->hasSecondFactorAuthenticationEnabled());
        $this->assertAllChecks(false);
    }

    public function testTotpPassesBothGeneralAndMethodSpecificChecks(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->assertAllChecks(true);
    }

    public function testRecencyExpiresWhileAuthenticationRemainsTrue(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->clock->sleep(599);
        $this->assertAllChecks(true);
        $this->clock->sleep(1);
        self::assertTrue($this->authorization->isGranted('IS_AUTHENTICATED_2FA'));
        self::assertTrue($this->authorization->isGranted('IS_AUTHENTICATED_TOTP'));
        self::assertFalse($this->authorization->isGranted('IS_AUTHENTICATED_2FA_RECENTLY'));
        self::assertFalse($this->authorization->isGranted('IS_AUTHENTICATED_TOTP_RECENTLY'));
    }

    public function testAnotherMethodDoesNotCountAsTotpOrRefreshItsTimestamp(): void
    {
        $this->secondFactorProofStore->record($this->user, 'another_factor');
        self::assertTrue($this->authorization->isGranted('IS_AUTHENTICATED_2FA'));
        self::assertTrue($this->authorization->isGranted('IS_AUTHENTICATED_2FA_RECENTLY'));
        self::assertFalse($this->authorization->isGranted('IS_AUTHENTICATED_TOTP'));
        self::assertFalse($this->authorization->isGranted('IS_AUTHENTICATED_TOTP_RECENTLY'));
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->clock->sleep(600);
        $this->secondFactorProofStore->record($this->user, 'another_factor');
        self::assertTrue($this->authorization->isGranted('IS_AUTHENTICATED_2FA_RECENTLY'));
        self::assertTrue($this->authorization->isGranted('IS_AUTHENTICATED_TOTP'));
        self::assertFalse($this->authorization->isGranted('IS_AUTHENTICATED_TOTP_RECENTLY'));
    }

    public function testRememberedSessionRequiresExplicitSecondFactorProof(): void
    {
        $this->tokens->setToken(new RememberMeToken($this->user, 'main'));
        $this->assertAllChecks(false);
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->assertAllChecks(true);
        $this->clock->sleep(600);
        self::assertTrue($this->authorization->isGranted('IS_AUTHENTICATED_TOTP'));
        self::assertFalse($this->authorization->isGranted('IS_AUTHENTICATED_TOTP_RECENTLY'));
    }

    public function testIncompleteMfaCannotReuseEvidence(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->tokens->setToken(new TwoFactorToken(new UsernamePasswordToken($this->user, 'main', ['ROLE_USER']), null, 'main', ['totp']));
        $this->assertAllChecks(false);
        $this->tokens->setToken(null);
        $this->assertAllChecks(false);
    }

    public function testEvidenceDoesNotAuthorizeAnotherUser(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        $other = clone $this->user;
        new \ReflectionProperty(User::class, 'id')->setValue($other, 456);
        $this->tokens->setToken(new UsernamePasswordToken($other, 'main', ['ROLE_USER']));
        $this->assertAllChecks(false);
    }

    public function testOfflineUserChecksCannotReuseTheCurrentSessionsEvidence(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        self::assertFalse($this->authorization->isGrantedForUser($this->user, 'IS_AUTHENTICATED_TOTP'));
        self::assertFalse($this->authorization->isGrantedForUser($this->user, 'IS_AUTHENTICATED_2FA_RECENTLY'));
    }

    public function testUnknownAttributesAndLifetimeArgumentsAreNotSupported(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        self::assertFalse($this->authorization->isGranted('UNKNOWN_ATTRIBUTE'));
        self::assertFalse($this->authorization->isGranted('IS_AUTHENTICATED_TOTP_RECENTLY', 600));
    }

    private function assertAllChecks(bool $expected): void
    {
        foreach (['IS_AUTHENTICATED_2FA', 'IS_AUTHENTICATED_2FA_RECENTLY', 'IS_AUTHENTICATED_TOTP', 'IS_AUTHENTICATED_TOTP_RECENTLY'] as $attribute) {
            self::assertSame($expected, $this->authorization->isGranted($attribute), $attribute);
        }
    }
}
