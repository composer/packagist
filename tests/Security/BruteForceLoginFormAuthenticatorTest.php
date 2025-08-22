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

use App\Security\BruteForceLoginFormAuthenticator;
use App\Security\RecaptchaHelper;
use Beelab\Recaptcha2Bundle\Recaptcha\RecaptchaVerifier;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\HttpUtils;

class BruteForceLoginFormAuthenticatorTest extends TestCase
{
    private BruteForceLoginFormAuthenticator $authenticator;
    private HttpUtils&MockObject $httpUtils;
    private RecaptchaVerifier&MockObject $recaptchaVerifier;
    private UserProviderInterface&MockObject $userProvider;
    private RecaptchaHelper&MockObject $recaptchaHelper;
    private ManagerRegistry&MockObject $doctrine;

    protected function setUp(): void
    {
        $this->httpUtils = $this->createMock(HttpUtils::class);
        $this->recaptchaVerifier = $this->createMock(RecaptchaVerifier::class);
        $this->userProvider = $this->createMock(UserProviderInterface::class);
        $this->recaptchaHelper = $this->createMock(RecaptchaHelper::class);
        $this->doctrine = $this->createMock(ManagerRegistry::class);

        $this->authenticator = new BruteForceLoginFormAuthenticator($this->httpUtils, $this->recaptchaVerifier, $this->userProvider, $this->recaptchaHelper, $this->doctrine);
    }

    #[DataProvider('supportsProvider')]
    public function testSupports(string $method, string $route, bool $expected): void
    {
        $request = new Request();
        $request->setMethod($method);
        $request->attributes->set('_route', $route);

        $this->assertSame($expected, $this->authenticator->supports($request));
    }

    public static function supportsProvider(): array
    {
        return [
            ['POST', 'login', true],
            ['GET', 'login', false],
            ['POST', 'route', false],
        ];
    }

    public function testOnAuthenticationSuccess(): void
    {
        $request = new Request();
        $request->setSession($this->createMock(SessionInterface::class));
        $token = $this->createMock(UsernamePasswordToken::class);

        $this->httpUtils
            ->expects($this->once())
            ->method('createRedirectResponse')
            ->with($request, $this->equalTo('home'))
            ->willReturn(new RedirectResponse('/'));

        $this->recaptchaHelper
            ->expects($this->once())
            ->method('clearCounter');

        $this->authenticator->onAuthenticationSuccess($request, $token, 'main');
    }

    public function testOnAuthenticationFailureIncreaseCounter(): void
    {
        $request = new Request();
        $request->setSession($this->createMock(SessionInterface::class));
        $exception = new AuthenticationException();

        $this->recaptchaHelper
            ->expects($this->once())
            ->method('increaseCounter');

        $this->httpUtils
            ->expects($this->once())
            ->method('createRedirectResponse')
            ->with($request, $this->equalTo('login'))
            ->willReturn(new RedirectResponse('/'));

        $this->authenticator->onAuthenticationFailure($request, $exception);
    }
}
