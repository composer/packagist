<?php declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use KnpU\OAuth2ClientBundle\Client\Provider\GithubClient;
use KnpU\OAuth2ClientBundle\Security\Authenticator\SocialAuthenticator;
use KnpU\OAuth2ClientBundle\Client\Provider\FacebookClient;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use League\OAuth2\Client\Provider\GithubResourceOwner;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class GitHubAuthenticator extends SocialAuthenticator
{
    use DoctrineTrait;

    private ClientRegistry $clientRegistry;
    private ManagerRegistry $doctrine;
    private UrlGeneratorInterface $router;
    private UserPasswordEncoderInterface $passwordEncoder;

    public function __construct(ClientRegistry $clientRegistry, ManagerRegistry $doctrine, UrlGeneratorInterface $router, UserPasswordEncoderInterface $passwordEncoder)
    {
        $this->clientRegistry = $clientRegistry;
        $this->doctrine = $doctrine;
        $this->router = $router;
        $this->passwordEncoder = $passwordEncoder;
    }

    public function supports(Request $request)
    {
        // continue ONLY if the current ROUTE matches the check ROUTE
        return $request->attributes->get('_route') === 'login_github_check';
    }

    public function getCredentials(Request $request)
    {
        return $this->fetchAccessToken($this->getGitHubClient());
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        /** @var GithubResourceOwner $ghUser */
        $ghUser = $this->getGitHubClient()->fetchUserFromToken($credentials);
        if (!$ghUser->getId() || $ghUser->getId() <= 0) {
            throw new \LogicException('Failed to load info from GitHub');
        }

        $userRepo = $this->getEM()->getRepository(User::class);

        // Logged in with GitHub already
        $existingUser = $userRepo->findOneBy(['githubId' => $ghUser->getId()]);
        if ($existingUser) {
            return $existingUser;
        }

        if ($userRepo->findOneBy(['username' => $ghUser->getNickname()])) {
            throw new AccountUsernameExistsWithoutGitHubException($ghUser->getNickname());
        }

        $provider = $this->getGitHubClient()->getOAuth2Provider();
        $request = $provider->getAuthenticatedRequest('GET', 'https://api.github.com/user/emails', $credentials);
        $response = $provider->getParsedResponse($request);
        $email = null;
        foreach ($response as $item) {
            if ($item['verified'] === true) {
                if ($userRepo->findOneBy(['email' => $item['email']])) {
                    throw new AccountEmailExistsWithoutGitHubException($item['email']);
                }

                if ($item['primary'] === true || $email === null) {
                    $email = $item['email'];
                }
            }
        }

        if (!$email) {
            throw new NoVerifiedGitHubEmailFoundException();
        }

        $user = new User();
        $user->setEmail($email);
        $user->setUsername($ghUser->getNickname());
        $user->setGithubId((string) $ghUser->getId());
        $user->setGithubToken($credentials->getToken());
        $user->setGithubScope($credentials->getValues()['scope']);

        // encode the plain password
        $user->setPassword(
            $this->passwordEncoder->encodePassword(
                $user,
                hash('sha512', random_bytes(60))
            )
        );

        $user->setLastLogin(new \DateTimeImmutable());
        $user->initializeApiToken();
        $user->setEnabled(true);

        $this->getEM()->persist($user);
        $this->getEM()->flush();

        // TODO when migrating to an authenticator should be able to set this on the session:
        // $request->getSession()->addFlash('success', 'A new account was automatically created. You are now logged in.');

        return $user;
    }

    private function getGitHubClient(): GithubClient
    {
        return $this->clientRegistry->getClient('github');
	}

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        $targetUrl = $this->router->generate('home');

        return new RedirectResponse($targetUrl);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());

        if ($message === 'Username could not be found.') {
            $message = 'No Packagist.org account found that is connected to your GitHub account. Please register an account and connect it to GitHub first.';
        }

        /** @var \Symfony\Component\HttpFoundation\Session\Session */
        $session = $request->getSession();
        $session->getFlashBag()->add('warning', $message);

        return new RedirectResponse($this->router->generate('login'), Response::HTTP_TEMPORARY_REDIRECT);
    }

    /**
     * Called when authentication is needed, but it's not sent.
     * This redirects to the 'login'.
     */
    public function start(Request $request, AuthenticationException $authException = null)
    {
        return new RedirectResponse($this->router->generate('login'), Response::HTTP_TEMPORARY_REDIRECT);
    }

    // ...
}
