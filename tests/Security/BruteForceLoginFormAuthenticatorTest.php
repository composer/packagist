<?php declare(strict_types=1);

namespace App\Tests\Security;

use Beelab\Recaptcha2Bundle\Recaptcha\RecaptchaVerifier;
use App\Security\BruteForceLoginFormAuthenticator;
use App\Security\RecaptchaHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class BruteForceLoginFormAuthenticatorTest extends TestCase
{
    /** @var BruteForceLoginFormAuthenticator */
    private $authenticator;
    /** @var UrlGeneratorInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $urlGenerator;
    /** @var RecaptchaVerifier&\PHPUnit\Framework\MockObject\MockObject */
    private $recaptchaVerifier;
    /** @var UserPasswordEncoderInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $passwordEncoder;
    /** @var RecaptchaHelper&\PHPUnit\Framework\MockObject\MockObject */
    private $recaptchaHelper;

    protected function setUp(): void
    {
        $this->urlGenerator = $this->getMockBuilder(UrlGeneratorInterface::class)->disableOriginalConstructor()->getMock();
        $this->recaptchaVerifier = $this->getMockBuilder(RecaptchaVerifier::class)->disableOriginalConstructor()->getMock();
        $this->passwordEncoder = $this->getMockBuilder(UserPasswordEncoderInterface::class)->disableOriginalConstructor()->getMock();
        $this->recaptchaHelper = $this->getMockBuilder(RecaptchaHelper::class)->disableOriginalConstructor()->getMock();

        $this->authenticator = new BruteForceLoginFormAuthenticator($this->urlGenerator, $this->recaptchaVerifier, $this->passwordEncoder, $this->recaptchaHelper);
    }

    /**
     * @dataProvider supportsProvider
     */
    public function testSupports(string $method, string $route, bool $expected): void
    {
        $request = new Request();
        $request->setMethod($method);
        $request->attributes->set('_route', $route);

        $this->assertSame($expected, $this->authenticator->supports($request));
    }

    public function supportsProvider(): array
    {
        return [
            ['POST', BruteForceLoginFormAuthenticator::LOGIN_ROUTE, true],
            ['GET', BruteForceLoginFormAuthenticator::LOGIN_ROUTE, false],
            ['POST', 'route', false],
        ];
    }

    public function testGetUser(): void
    {
        $user = $this->getMockBuilder(UserInterface::class)->disableOriginalConstructor()->getMock();
        $userProvider = $this->getMockBuilder(UserProviderInterface::class)->disableOriginalConstructor()->getMock();
        $userProvider
            ->expects($this->once())
            ->method('loadUserByUsername')
            ->with($this->equalTo('username'))
            ->willReturn($user);

        $this->recaptchaHelper
            ->expects($this->once())
            ->method('requiresRecaptcha')
            ->willReturn(true);

        $this->recaptchaVerifier
            ->expects($this->once())
            ->method('verify')
            ->with($this->equalTo('recaptcha'));

        $credentials = [
            'username' => 'username',
            'password' => 'password',
            'ip' => '127.0.0.1',
            'recaptcha' => 'recaptcha',
        ];

        $this->assertSame($user, $this->authenticator->getUser($credentials, $userProvider));
    }

    public function testOnAuthenticationSuccess(): void
    {
        $request = new Request();
        $request->setSession($this->getMockBuilder(SessionInterface::class)->disableOriginalConstructor()->getMock());
        $token = $this->getMockBuilder(UsernamePasswordToken::class)->disableOriginalConstructor()->getMock();

        $this->urlGenerator
            ->expects($this->once())
            ->method('generate')
            ->with($this->equalTo('home'))
            ->willReturn('/');

        $this->recaptchaHelper
            ->expects($this->once())
            ->method('clearCounter');

        $this->authenticator->onAuthenticationSuccess($request, $token, 'main');
    }

    public function testOnAuthenticationFailureIncreaseCounter(): void
    {
        $request = new Request();
        $exception = new AuthenticationException();

        $this->recaptchaHelper
            ->expects($this->once())
            ->method('increaseCounter');


        $this->urlGenerator
            ->expects($this->once())
            ->method('generate')
            ->with($this->equalTo(BruteForceLoginFormAuthenticator::LOGIN_ROUTE))
            ->willReturn('/');

        $this->authenticator->onAuthenticationFailure($request, $exception);
    }
}
