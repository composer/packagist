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
use Composer\Pcre\Preg;
use Doctrine\Persistence\ManagerRegistry;
use KnpU\OAuth2ClientBundle\Client\Provider\GithubClient;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use League\OAuth2\Client\Provider\GithubResourceOwner;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\HttpUtils;

class GitHubAuthenticator extends OAuth2Authenticator
{
    use DoctrineTrait;

    public function __construct(
        private ClientRegistry $clientRegistry,
        private ManagerRegistry $doctrine,
        private HttpUtils $httpUtils,
        private UserPasswordHasherInterface $passwordHasher,
        private NoPrivateNetworkHttpClient $httpClient,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // continue ONLY if the current ROUTE matches the check ROUTE
        return $request->attributes->get('_route') === 'login_github_check';
    }

    /**
     * @inheritDoc
     */
    public function authenticate(Request $request): Passport
    {
        $accessToken = $this->fetchAccessToken($this->getGitHubClient());

        // enable remember me for GH login
        $request->attributes->set('_remember_me', true);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($request, $accessToken) {
                /** @var GithubResourceOwner $ghUser */
                $ghUser = $this->getGitHubClient()->fetchUserFromToken($accessToken);
                if (!$ghUser->getId() || $ghUser->getId() <= 0) {
                    throw new \LogicException('Failed to load info from GitHub');
                }

                $userRepo = $this->getEM()->getRepository(User::class);

                // Logged in with GitHub already
                $existingUser = $userRepo->findOneBy(['githubId' => $ghUser->getId()]);
                if ($existingUser) {
                    $validToken = true;
                    // legacy token, update it with a new one
                    if (!Preg::isMatch('{^gh[a-z]_}', (string) $existingUser->getGithubToken())) {
                        $validToken = false;
                    }
                    // validate that the token we have on file is still correct
                    if ($validToken && $this->httpClient->request('GET', 'https://api.github.com/user', ['headers' => ['Authorization: token '.$existingUser->getGithubToken()]])->getStatusCode() === 401) {
                        $validToken = false;
                    }
                    if (!$validToken) {
                        $existingUser->setGithubToken($accessToken->getToken());
                        $this->getEM()->persist($existingUser);
                        $this->getEM()->flush();
                    }

                    return $existingUser;
                }

                $nickname = $ghUser->getNickname();
                if (null === $nickname) {
                    throw new NoGitHubNicknameFoundException();
                }

                if ($userRepo->findOneBy(['usernameCanonical' => mb_strtolower($nickname)])) {
                    throw new AccountUsernameExistsWithoutGitHubException($nickname);
                }

                $provider = $this->getGitHubClient()->getOAuth2Provider();
                $authRequest = $provider->getAuthenticatedRequest('GET', 'https://api.github.com/user/emails', $accessToken);
                /** @var array<array{verified: bool, email: string, primary: bool}> $authResponse */
                $authResponse = $provider->getParsedResponse($authRequest);
                $email = null;
                foreach ($authResponse as $item) {
                    if ($item['verified'] === true) {
                        if ($userRepo->findOneBy(['emailCanonical' => mb_strtolower($item['email'])])) {
                            throw new AccountEmailExistsWithoutGitHubException($item['email']);
                        }

                        if ($item['primary'] === true || $email === null) {
                            $email = $item['email'];
                        }
                    }
                }

                if (null === $email) {
                    throw new NoVerifiedGitHubEmailFoundException();
                }

                $user = new User();
                $user->setEmail($email);
                $user->setUsername($nickname);
                $user->setGithubId((string) $ghUser->getId());
                $user->setGithubToken($accessToken->getToken());
                $user->setGithubScope($accessToken->getValues()['scope']);

                // encode the plain password
                $user->setPassword(
                    $this->passwordHasher->hashPassword(
                        $user,
                        bin2hex(random_bytes(20))
                    )
                );

                $user->setLastLogin(new \DateTimeImmutable());
                $user->initializeApiToken();
                $user->setEnabled(true);

                $this->getEM()->persist($user);
                $this->getEM()->flush();

                $session = $request->getSession();
                assert($session instanceof FlashBagAwareSessionInterface);
                $session->getFlashBag()->add('success', 'A new account was automatically created. You are now logged in.');

                return $user;
            }),
            [new RememberMeBadge()]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
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
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());

        if ($message === 'Username could not be found.') {
            $message = 'No Packagist.org account found that is connected to your GitHub account. Please register an account and connect it to GitHub first.';
        }

        $session = $request->getSession();
        assert($session instanceof FlashBagAwareSessionInterface);
        $session->getFlashBag()->add('warning', $message);

        return $this->httpUtils->createRedirectResponse($request, 'login', Response::HTTP_FOUND);
    }

    private function getGitHubClient(): GithubClient
    {
        /** @var GithubClient $client */
        $client = $this->clientRegistry->getClient('github');

        return $client;
    }
}
