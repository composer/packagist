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

namespace App\Controller;

use App\Entity\User;
use App\Security\UserNotifier;
use App\Service\Scheduler;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Exception\InvalidStateException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GithubResourceOwner;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Component\Finder\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class GitHubLoginController extends Controller
{
    /**
     * Link to this controller to start the "connect" process
     */
    #[Route(path: '/connect/github', name: 'connect_github_start')]
    public function connect(ClientRegistry $clientRegistry): RedirectResponse
    {
        $user = $this->getUser();
        if (!is_object($user)) {
            throw $this->createAccessDeniedException('This user does not have access to this section.');
        }

        return $clientRegistry
            ->getClient('github')
            ->redirect([
                // the scopes you want to access
                'admin:repo_hook', 'read:org', 'user:email',
            ], [
                'redirect_uri' => $this->generateUrl('connect_github_check', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
    }

    /**
     * Link to this controller to start the "connect" process
     */
    #[Route(path: '/login/github', name: 'login_github_start')]
    public function login(ClientRegistry $clientRegistry): RedirectResponse
    {
        return $clientRegistry
            ->getClient('github')
            ->redirect([
                // the scopes you want to access
                'admin:repo_hook', 'read:org', 'user:email',
            ], [
                'redirect_uri' => $this->generateUrl('login_github_check', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
    }

    /**
     * After going to GitHub, you're redirected back here
     * because this is the "redirect_route" you configured
     * in config/packages/knpu_oauth2_client.yaml
     */
    #[Route(path: '/connect/github/check', name: 'connect_github_check')]
    public function connectCheck(Request $request, ClientRegistry $clientRegistry, Scheduler $scheduler, UserNotifier $userNotifier, #[CurrentUser] User $user): RedirectResponse
    {
        /** @var \KnpU\OAuth2ClientBundle\Client\Provider\GithubClient $client */
        $client = $clientRegistry->getClient('github');
        try {
            /** @var AccessToken $token */
            $token = $client->getAccessToken();
            if ($user->getGithubId()) {
                $this->addFlash('error', 'You must disconnect your GitHub account before you can connect a new one.');

                return $this->redirectToRoute('edit_profile');
            }

            /** @var GithubResourceOwner $ghUser */
            $ghUser = $client->fetchUserFromToken($token);
            if (!$ghUser->getId() || $ghUser->getId() <= 0) {
                throw new \LogicException('Failed to load info from GitHub');
            }

            $previousUser = $this->getEM()->getRepository(User::class)->findOneBy(['githubId' => $ghUser->getId()]);

            // The account is already connected. Do nothing
            if ($previousUser === $user) {
                return $this->redirectToRoute('edit_profile');
            }

            $oldScope = $user->getGithubScope() ?: '';
            $user->setGithubId((string) $ghUser->getId());
            $user->setGithubToken($token->getToken());
            $user->setGithubScope($token->getValues()['scope']);

            // 'disconnect' a previous account
            if (null !== $previousUser) {
                $this->disconnectUser($previousUser);
                $this->getEM()->persist($previousUser);
            }

            $this->getEM()->persist($user);
            $this->getEM()->flush();

            $scheduler->scheduleUserScopeMigration($user->getId(), $oldScope, $user->getGithubScope() ?? '');
            $userNotifier->notifyChange($user->getEmail(), 'A Github account has been connected to your Packagist.org account.');

            $this->addFlash('success', 'You have connected your GitHub account '.$ghUser->getNickname().' to your Packagist.org account.');
        } catch (IdentityProviderException | InvalidStateException $e) {
            $this->addFlash('error', 'Failed OAuth Login: '.$e->getMessage());
        }

        return $this->redirectToRoute('edit_profile');
    }

    /**
     * After going to GitHub, you're redirected back here
     * because this is the "redirect_route" you configured
     * in config/packages/knpu_oauth2_client.yaml
     */
    #[Route(path: '/login/github/check', name: 'login_github_check', defaults: ['_format' => 'html'])]
    public function loginCheck(Request $request, ClientRegistry $clientRegistry): void
    {
    }

    #[Route(path: '/oauth/github/disconnect', name: 'user_github_disconnect')]
    public function disconnect(Request $req, CsrfTokenManagerInterface $csrfTokenManager, UserNotifier $userNotifier, #[CurrentUser] User $user): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('unlink_github', $req->query->get('token', ''))) {
            throw new AccessDeniedException('Invalid CSRF token');
        }

        if ($user->getGithubId()) {
            $this->disconnectUser($user);
            $this->getEM()->persist($user);
            $this->getEM()->flush();
            $userNotifier->notifyChange($user->getEmail(), 'Your GitHub account has been disconnected from your Packagist.org account.');
        }

        return $this->redirectToRoute('edit_profile');
    }

    private function disconnectUser(User $user): void
    {
        $user->setGithubId(null);
        $user->setGithubToken(null);
        $user->setGithubScope(null);
    }
}
