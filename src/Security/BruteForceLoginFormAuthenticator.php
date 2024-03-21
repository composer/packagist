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

namespace App\Security;

use App\Entity\User;
use App\Util\DoctrineTrait;
use App\Validator\RateLimitingRecaptcha;
use Beelab\Recaptcha2Bundle\Recaptcha\RecaptchaException;
use Beelab\Recaptcha2Bundle\Recaptcha\RecaptchaVerifier;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\HttpUtils;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * @phpstan-type Credentials array{username: string, password: string, ip: string|null, recaptcha: string}
 * @template-covariant TUser of UserInterface
 */
class BruteForceLoginFormAuthenticator extends AbstractLoginFormAuthenticator implements AuthenticationEntryPointInterface
{
    use DoctrineTrait;

    /**
     * @param UserProviderInterface<TUser> $userProvider
     */
    public function __construct(
        private HttpUtils $httpUtils,
        private RecaptchaVerifier $recaptchaVerifier,
        private UserProviderInterface $userProvider,
        private RecaptchaHelper $recaptchaHelper,
        private ManagerRegistry $doctrine,
    ) {
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->httpUtils->generateUri($request, 'login');
    }

    public function supports(Request $request): bool
    {
        return 'login' === $request->attributes->get('_route') && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $credentials = $this->getCredentials($request);

        $this->validateRecaptcha($credentials);

        $passport = new Passport(
            new UserBadge($credentials['username'], [$this->userProvider, 'loadUserByIdentifier']),
            new PasswordCredentials($credentials['password']),
            [new RememberMeBadge()]
        );

        if ($this->userProvider instanceof PasswordUpgraderInterface) {
            $passport->addBadge(new PasswordUpgradeBadge($credentials['password'], $this->userProvider));
        }

        return $passport;
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        return new UsernamePasswordToken($passport->getUser(), $firewallName, $passport->getUser()->getRoles());
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $this->recaptchaHelper->clearCounter(RecaptchaContext::fromRequest($request));

        if ($token->getUser() instanceof User) {
            $token->getUser()->setLastLogin(new \DateTimeImmutable());
            $this->getEM()->persist($token->getUser());
            $this->getEM()->flush();
        }

        if (($targetPath = $request->getSession()->get('_security.'.$firewallName.'.target_path')) && is_string($targetPath)) {
            return $this->httpUtils->createRedirectResponse($request, $targetPath, Response::HTTP_FOUND);
        }

        return $this->httpUtils->createRedirectResponse($request, 'home', Response::HTTP_FOUND);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $this->recaptchaHelper->increaseCounter(RecaptchaContext::fromRequest($request));

        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return $this->httpUtils->createRedirectResponse($request, 'login', Response::HTTP_FOUND);
    }

    /**
     * @return Credentials
     */
    private function getCredentials(Request $request): array
    {
        $credentials = [
            'username' => $request->request->get('_username'),
            'password' => (string) $request->request->get('_password'),
            'ip' => $request->getClientIp(),
            'recaptcha' => (string) $request->request->get('g-recaptcha-response'),
        ];

        if (!\is_string($credentials['username'])) {
            throw new BadRequestHttpException(sprintf('The key "_username" must be a string, "%s" given.', \gettype($credentials['username'])));
        }

        $credentials['username'] = trim($credentials['username']);

        if (\strlen($credentials['username']) > UserBadge::MAX_USERNAME_LENGTH) {
            throw new BadCredentialsException('Invalid username.');
        }

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $credentials['username']);

        return $credentials;
    }

    /**
     * @param Credentials $credentials
     */
    private function validateRecaptcha(array $credentials): void
    {
        if ($this->recaptchaHelper->requiresRecaptcha(new RecaptchaContext($credentials['ip'] ?? '', $credentials['username'], (bool) $credentials['recaptcha']))) {
            if (!$credentials['recaptcha']) {
                throw new CustomUserMessageAuthenticationException('We detected too many failed login attempts. Please log in again with ReCaptcha.');
            }

            try {
                $this->recaptchaVerifier->verify($credentials['recaptcha']);
            } catch (RecaptchaException $e) {
                throw new CustomUserMessageAuthenticationException(RateLimitingRecaptcha::INVALID_RECAPTCHA_MESSAGE);
            }
        }
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->httpUtils->createRedirectResponse($request, 'login', Response::HTTP_FOUND);
    }
}
