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

namespace App\Tests\EventListener;

use App\Entity\User;
use App\EventListener\SecondFactorAuthenticationListener;
use App\Security\Passport\Badge\ResolvedTotpCodeCredentials;
use App\Security\SecondFactorProofStore;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorToken;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorCodeEvent;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

class SecondFactorAuthenticationListenerTest extends TestCase
{
    private User $user;
    private Request $request;
    private TokenStorage $tokens;
    private SecondFactorProofStore $secondFactorProofStore;
    private SecondFactorAuthenticationListener $listener;
    private UsernamePasswordToken $authenticated;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->user->setUsername('alice');
        $this->user->setTotpSecret('JBSWY3DPEHPK3PXP');
        new \ReflectionProperty(User::class, 'id')->setValue($this->user, 123);
        $this->request = new Request();
        $this->request->setSession(new Session(new MockArraySessionStorage()));
        $requests = new RequestStack();
        $requests->push($this->request);
        $this->tokens = new TokenStorage();
        $this->authenticated = new UsernamePasswordToken($this->user, 'main', ['ROLE_USER']);
        $this->tokens->setToken(new TwoFactorToken($this->authenticated, null, 'main', ['totp']));
        $this->secondFactorProofStore = new SecondFactorProofStore($requests, new MockClock());
        $this->listener = new SecondFactorAuthenticationListener($this->secondFactorProofStore, $this->tokens, $requests);
    }

    public function testCompletedTotpChallengeRecordsEvidence(): void
    {
        $this->listener->codeValidated(new TwoFactorCodeEvent($this->user, '123456'));
        $this->listener->completed(new TwoFactorAuthenticationEvent($this->request, $this->authenticated));
        self::assertTrue($this->secondFactorProofStore->hasRecentProof($this->user));
    }

    public function testCompletionWithoutAValidatedTotpCodeDoesNotCount(): void
    {
        $this->listener->completed(new TwoFactorAuthenticationEvent($this->request, $this->authenticated));
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
    }

    public function testAnotherProviderIsNotRecordedAsTotp(): void
    {
        $this->tokens->setToken(new TwoFactorToken($this->authenticated, null, 'main', ['another_provider']));
        $this->listener->codeValidated(new TwoFactorCodeEvent($this->user, '123456'));
        $this->listener->completed(new TwoFactorAuthenticationEvent($this->request, $this->authenticated));
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
    }

    public function testASecondFactorCannotBeCreditedToAnotherUser(): void
    {
        $other = clone $this->user;
        new \ReflectionProperty(User::class, 'id')->setValue($other, 456);
        $this->listener->codeValidated(new TwoFactorCodeEvent($other, '123456'));
        $this->listener->completed(new TwoFactorAuthenticationEvent($this->request, $this->authenticated));
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
    }

    public function testNewPrimaryLoginClearsEvidence(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->listener->loggedIn($this->loginEvent());
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
    }

    public function testPasswordResetWithAValidatedCodeCounts(): void
    {
        $event = $this->loginEvent();
        $event->getPassport()->addBadge(new ResolvedTotpCodeCredentials());
        $this->listener->loggedIn($event);
        self::assertTrue($this->secondFactorProofStore->hasRecentProof($this->user));
    }

    public function testLogoutAndImpersonationClearEvidence(): void
    {
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->listener->loggedOut(new LogoutEvent($this->request, $this->authenticated));
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
        $this->secondFactorProofStore->record($this->user, 'totp');
        $this->listener->switchedUser(new SwitchUserEvent($this->request, $this->user));
        self::assertFalse($this->secondFactorProofStore->hasRecentProof($this->user));
    }

    private function loginEvent(): LoginSuccessEvent
    {
        return new LoginSuccessEvent(
            $this->createStub(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge('alice', fn () => $this->user)),
            $this->authenticated,
            $this->request,
            null,
            'main',
        );
    }
}
