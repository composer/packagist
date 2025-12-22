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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
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
    private RecaptchaHelper&MockObject $recaptchaHelper;

    protected function setUp(): void
    {
        $recaptchaVerifier = $this->createStub(RecaptchaVerifier::class);
        $userProvider = $this->createStub(UserProviderInterface::class);
        $doctrine = $this->createStub(ManagerRegistry::class);
        $this->httpUtils = $this->createMock(HttpUtils::class);
        $this->recaptchaHelper = $this->createMock(RecaptchaHelper::class);

        $this->authenticator = new BruteForceLoginFormAuthenticator($this->httpUtils, $recaptchaVerifier, $userProvider, $this->recaptchaHelper, $doctrine);
    }

    #[DataProvider('supportsProvider')]
    #[AllowMockObjectsWithoutExpectations]
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
        $request->setSession($this->createStub(SessionInterface::class));
        $token = $this->createStub(UsernamePasswordToken::class);

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
        $request->setSession($this->createStub(SessionInterface::class));
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
