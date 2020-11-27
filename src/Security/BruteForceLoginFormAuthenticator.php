<?php declare(strict_types=1);

namespace App\Security;

use Beelab\Recaptcha2Bundle\Recaptcha\RecaptchaException;
use Beelab\Recaptcha2Bundle\Recaptcha\RecaptchaVerifier;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\Authenticator\AbstractFormLoginAuthenticator;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class BruteForceLoginFormAuthenticator extends AbstractFormLoginAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'login';

    /** @var UrlGeneratorInterface */
    private $urlGenerator;
    /** @var RecaptchaVerifier */
    private $recaptchaVerifier;
    /** @var UserPasswordEncoderInterface */
    private $passwordEncoder;
    /** @var RecaptchaHelper */
    private $recaptchaHelper;

    public function __construct(
        UrlGeneratorInterface $urlGenerator,
        RecaptchaVerifier $recaptchaVerifier,
        UserPasswordEncoderInterface $passwordEncoder,
        RecaptchaHelper $recaptchaHelper
    ) {
        $this->urlGenerator = $urlGenerator;
        $this->recaptchaVerifier = $recaptchaVerifier;
        $this->passwordEncoder = $passwordEncoder;
        $this->recaptchaHelper = $recaptchaHelper;
    }

    public function supports(Request $request)
    {
        return self::LOGIN_ROUTE === $request->attributes->get('_route')
            && $request->isMethod('POST');
    }

    public function getCredentials(Request $request)
    {
        $credentials = [
            'username' => $request->request->get('_username'),
            'password' => $request->request->get('_password'),
            'ip' => $request->getClientIp(),
            'recaptcha' => $request->request->get(RecaptchaVerifier::GOOGLE_DEFAULT_INPUT),
        ];
        $request->getSession()->set(
            Security::LAST_USERNAME,
            $credentials['username']
        );

        return $credentials;
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        $this->validateRecaptcha($credentials);

        try {
            return $userProvider->loadUserByUsername($credentials['username']);
        } catch (UsernameNotFoundException $e) {
            // fail authentication with a custom error
            throw new CustomUserMessageAuthenticationException('Invalid credentials.', [], 0, $e);
        }
    }

    public function checkCredentials($credentials, UserInterface $user)
    {
        return $this->passwordEncoder->isPasswordValid($user, $credentials['password']);

    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        $this->recaptchaHelper->clearCounter($request);

        if ($targetPath = $this->getTargetPath($request->getSession(), $providerKey)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        $this->recaptchaHelper->increaseCounter($request);

        return parent::onAuthenticationFailure($request, $exception);
    }

    protected function getLoginUrl()
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }

    private function validateRecaptcha(array $credentials): void
    {
        if ($this->recaptchaHelper->requiresRecaptcha($credentials['ip'], $credentials['username'])) {
            if (!$credentials['recaptcha']) {
                throw new CustomUserMessageAuthenticationException('We detected too many failed login attempts. Please log in again with ReCaptcha.');
            }

            try {
                $this->recaptchaVerifier->verify($credentials['recaptcha']);
            } catch (RecaptchaException $e) {
                throw new CustomUserMessageAuthenticationException('Invalid ReCaptcha');
            }
        }
    }
}
