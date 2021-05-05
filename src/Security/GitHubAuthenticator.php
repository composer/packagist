<?php declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\Provider\GithubClient;
use KnpU\OAuth2ClientBundle\Security\Authenticator\SocialAuthenticator;
use KnpU\OAuth2ClientBundle\Client\Provider\FacebookClient;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class GitHubAuthenticator extends SocialAuthenticator
{
    private $clientRegistry;
    private $em;
    private $router;

    public function __construct(ClientRegistry $clientRegistry, EntityManagerInterface $em, RouterInterface $router)
    {
        $this->clientRegistry = $clientRegistry;
        $this->em = $em;
	    $this->router = $router;
    }

    public function supports(Request $request)
    {
        // continue ONLY if the current ROUTE matches the check ROUTE
        return $request->attributes->get('_route') === 'login_github_check';
    }

    public function getCredentials(Request $request)
    {
        // this method is only called if supports() returns true

        // For Symfony lower than 3.4 the supports method need to be called manually here:
        // if (!$this->supports($request)) {
        //     return null;
        // }

        return $this->fetchAccessToken($this->getGitHubClient());
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        $ghUser = $this->getGitHubClient()->fetchUserFromToken($credentials);
        if (!$ghUser->getId() || $ghUser->getId() <= 0) {
            throw new \LogicException('Failed to load info from GitHub');
        }

        // Logged in with GitHub already
        $existingUser = $this->em->getRepository(User::class)
            ->findOneBy(['githubId' => $ghUser->getId()]);
        if ($existingUser) {
            return $existingUser;
        }

        return null;


        // Do we have a matching user by email?
//        $user = $this->em->getRepository(User::class)
//            ->findOneBy(['email' => $email, 'enabled' => true]);

        // Maybe register the user by creating
        // a User object or showing/prefilling a registration form?
        // TODO see OAuthRegistrationFormHandler to handle registration
        // + the following

        /*

        // TODO requires 'user:email' in scopes

        $provider = $client->getOAuth2Provider();
        $request = $provider->getAuthenticatedRequest(
            'GET',
            'https://api.github.com/user/emails',
            $token
        );
        $array = $provider->getParsedResponse($request);
        foreach ($array as $item) {
            if ($item['primary'] === true && $item['verified'] === true) {
                $email = $item['email'];
                break;
            }
        }

        dd($user, $user->getName(), $email);


        return $user;
        */
    }

    private function getGitHubClient(): GithubClient
    {
        return $this->clientRegistry->getClient('github');
	}

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        // change "app_homepage" to some route in your app
        $targetUrl = $this->router->generate('home');

        return new RedirectResponse($targetUrl);

        // or, on success, let the request continue to be handled by the controller
        //return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());

        if ($message === 'Username could not be found.') {
            $message = 'No Packagist.org account found that is connected to your GitHub account. Please register an account and connect it to GitHub first.';
        }

        /** @phpstan-ignore-next-line */
        $request->getSession()->getFlashBag()->add('warning', $message);

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
