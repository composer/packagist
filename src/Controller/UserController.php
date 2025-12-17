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

use App\Attribute\VarName;
use App\Entity\Package;
use App\Entity\TemporaryTwoFactorUser;
use App\Entity\User;
use App\Entity\Version;
use App\Entity\VersionRepository;
use App\Form\Model\EnableTwoFactorRequest;
use App\Form\Type\EnableTwoFactorAuthType;
use App\Model\FavoriteManager;
use App\Model\PackageManager;
use App\Model\ProviderManager;
use App\Model\RedisAdapter;
use App\Security\TwoFactorAuthManager;
use App\Service\Scheduler;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Pagerfanta\Pagerfanta;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UserController extends Controller
{
    private ProviderManager $providerManager;
    private Scheduler $scheduler;
    private PackageManager $packageManager;

    public function __construct(ProviderManager $providerManager, Scheduler $scheduler, PackageManager $packageManager)
    {
        $this->providerManager = $providerManager;
        $this->scheduler = $scheduler;
        $this->packageManager = $packageManager;
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/trigger-github-sync/', name: 'user_github_sync')]
    public function triggerGitHubSyncAction(#[CurrentUser] User $user): RedirectResponse
    {
        if (!$user->getGithubToken()) {
            $this->addFlash('error', 'You must connect your user account to github to sync packages.');

            return $this->redirectToRoute('my_profile');
        }

        if (!$user->getGithubScope()) {
            $this->addFlash('error', 'Please log out and log in with GitHub again to make sure the correct GitHub permissions are granted.');

            return $this->redirectToRoute('my_profile');
        }

        $this->scheduler->scheduleUserScopeMigration($user->getId(), '', $user->getGithubScope());

        sleep(5);

        $this->addFlash('success', 'User sync scheduled. It might take a few seconds to run through, make sure you refresh then to check if any packages still need sync.');

        return $this->redirectToRoute('my_profile');
    }

    #[Route(path: '/spammers/{name}/', name: 'mark_spammer', methods: ['POST'])]
    public function markSpammerAction(Request $req, #[VarName('name')] User $user): RedirectResponse
    {
        if (!$this->isGranted('ROLE_ANTISPAM')) {
            throw $this->createAccessDeniedException('This user can not mark others as spammers');
        }

        $form = $this->createFormBuilder([])->getForm();

        $form->submit($req->request->all('form'));
        if ($form->isSubmitted() && $form->isValid()) {
            $user->addRole('ROLE_SPAMMER');
            $user->setEnabled(false);

            $em = $this->getEM();

            $em->getConnection()->executeStatement(
                'UPDATE package p JOIN maintainers_packages mp ON mp.package_id = p.id
                 SET frozen = "spam", indexedAt = NULL
                 WHERE mp.user_id = :userId',
                ['userId' => $user->getId()]
            );

            /** @var VersionRepository $versionRepo */
            $versionRepo = $em->getRepository(Version::class);
            $qb = $em
                ->getRepository(Package::class)
                ->createQueryBuilder('p');
            $packages = $qb->leftJoin('p.maintainers', 'm')
                ->where($qb->expr()->in('m.id', ':maintainer'))
                ->setParameter('maintainer', [$user->getId()])
                ->getQuery()->getResult();

            foreach ($packages as $package) {
                foreach ($package->getVersions() as $version) {
                    $versionRepo->remove($version);
                }

                $this->providerManager->deletePackage($package);
                $this->packageManager->deletePackageMetadata($package->getName());
                $this->packageManager->deletePackageCdnMetadata($package->getName());
                $this->packageManager->deletePackageSearchIndex($package->getName());
            }

            $this->getEM()->flush();

            $this->addFlash('success', $user->getUsername().' has been marked as a spammer');
        }

        return $this->redirect(
            $this->generateUrl('user_profile', ['name' => $user->getUsername()])
        );
    }

    #[Route(path: '/users/{name}/favorites/', name: 'user_favorites', methods: ['GET'])]
    public function favoritesAction(Request $req, #[VarName('name')] User $user, LoggerInterface $logger, RedisClient $redis, FavoriteManager $favoriteManager): Response
    {
        try {
            if (!$redis->isConnected()) {
                $redis->connect();
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Could not connect to the Redis database.');
            $logger->notice($e->getMessage(), ['exception' => $e]);

            return $this->render('user/favorites.html.twig', ['user' => $user, 'packages' => []]);
        }

        $paginator = new Pagerfanta(
            new RedisAdapter($favoriteManager, $user)
        );

        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage(max(1, $req->query->getInt('page', 1)));

        return $this->render('user/favorites.html.twig', ['packages' => $paginator, 'user' => $user]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/users/{name}/favorites/', name: 'user_add_fav', defaults: ['_format' => 'json'], methods: ['POST'])]
    public function postFavoriteAction(Request $req, #[VarName('name')] User $user, #[CurrentUser] User $loggedUser, FavoriteManager $favoriteManager): Response
    {
        if ($user->getId() !== $loggedUser->getId()) {
            throw $this->createAccessDeniedException('You can only change your own favorites');
        }

        $packageName = $req->request->getString('package');
        $package = $this->getEM()
            ->getRepository(Package::class)
            ->findOneBy(['name' => $packageName]);

        if ($package === null) {
            throw $this->createNotFoundException('The given package "'.$packageName.'" was not found.');
        }

        $favoriteManager->markFavorite($user, $package);

        return new Response('{"status": "success"}', 201);
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/users/{name}/favorites/{package}', name: 'user_remove_fav', defaults: ['_format' => 'json'], requirements: ['package' => '[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?'], methods: ['DELETE'])]
    public function deleteFavoriteAction(
        #[VarName('name')]
        User $user,
        #[CurrentUser]
        User $loggedUser,
        #[MapEntity(mapping: ['package' => 'name'])]
        Package $package,
        FavoriteManager $favoriteManager,
    ): Response {
        if ($user->getId() !== $loggedUser->getId()) {
            throw $this->createAccessDeniedException('You can only change your own favorites');
        }

        $favoriteManager->removeFavorite($user, $package);

        return new Response('{"status": "success"}', 204);
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/users/{name}/delete', name: 'user_delete', methods: ['POST'])]
    public function deleteUserAction(#[VarName('name')] User $user, #[CurrentUser] User $loggedUser, Request $req, TokenStorageInterface $storage, EventDispatcherInterface $mainEventDispatcher): RedirectResponse
    {
        if (!$this->isGranted('ROLE_ADMIN') && $user->getId() !== $loggedUser->getId()) {
            throw $this->createAccessDeniedException('You cannot delete this user');
        }

        if (\count($user->getPackages()) > 0) {
            throw $this->createAccessDeniedException('The user has packages so it can not be deleted');
        }

        $form = $this->createFormBuilder([])->getForm();

        $form->submit($req->request->all('form'));
        if ($form->isSubmitted() && $form->isValid()) {
            $selfDelete = $user->getId() === $loggedUser->getId();

            $em = $this->getEM();
            $em->remove($user);
            $em->flush();

            // programmatic logout
            if ($selfDelete) {
                $logoutEvent = new LogoutEvent($req, $storage->getToken());
                $mainEventDispatcher->dispatch($logoutEvent);
                $response = $logoutEvent->getResponse();
                if (!$response instanceof Response) {
                    throw new \RuntimeException('No logout listener set the Response, make sure at least the DefaultLogoutListener is registered.');
                }
                $storage->setToken(null);
            }

            return $this->redirectToRoute('home');
        }

        return $this->redirectToRoute('user_profile', ['name' => $user->getUsername()]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/users/{name}/2fa/', name: 'user_2fa_configure', methods: ['GET'])]
    public function configureTwoFactorAuthAction(#[VarName('name')] User $user, #[CurrentUser] User $loggedUser, Request $req): Response
    {
        if (!$this->isGranted('ROLE_DISABLE_2FA') && $user->getId() !== $loggedUser->getId()) {
            throw $this->createAccessDeniedException('You cannot change this user\'s two-factor authentication settings');
        }

        if ($user->getId() === $loggedUser->getId()) {
            $backupCode = $req->getSession()->remove('backup_code');
        }

        return $this->render(
            'user/configure_two_factor_auth.html.twig',
            ['user' => $user, 'backup_code' => $backupCode ?? null]
        );
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/users/{name}/2fa/enable', name: 'user_2fa_enable', methods: ['GET', 'POST'])]
    public function enableTwoFactorAuthAction(Request $req, #[VarName('name')] User $user, #[CurrentUser] User $loggedUser, TotpAuthenticatorInterface $authenticator, TwoFactorAuthManager $authManager): Response
    {
        if ($user->getId() !== $loggedUser->getId()) {
            throw $this->createAccessDeniedException('You cannot change this user\'s two-factor authentication settings');
        }

        if ($user->isTotpAuthenticationEnabled()) {
            throw $this->createAccessDeniedException('Two-factor authentication is already enabled');
        }

        $secret = (string) $req->getSession()->get('2fa_secret') ?: $authenticator->generateSecret();
        // Temporarily store this code on the user, as we'll need it there to generate the
        // QR code and to check the confirmation code.  We won't actually save this change
        // until we've confirmed the code
        $temporary2faUser = new TemporaryTwoFactorUser($user, $secret);

        $enableRequest = new EnableTwoFactorRequest();
        $form = $this->createForm(EnableTwoFactorAuthType::class, $enableRequest, ['user' => $temporary2faUser])
            ->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setTotpSecret($secret);
            $req->getSession()->remove('2fa_secret');
            $authManager->enableTwoFactorAuth($user, $secret);
            $backupCode = $authManager->generateAndSaveNewBackupCode($user);

            $this->addFlash('success', 'Two-factor authentication has been enabled.');
            $req->getSession()->set('backup_code', $backupCode);

            return $this->redirectToRoute('user_2fa_confirm', ['name' => $user->getUsername()]);
        }

        $req->getSession()->set('2fa_secret', $secret);
        $qrContent = $authenticator->getQRContent($temporary2faUser);

        $qrCode = new Builder(
            writer: new SvgWriter(),
            writerOptions: [],
            data: $qrContent,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 200,
            margin: 0,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );

        return $this->render(
            'user/enable_two_factor_auth.html.twig',
            [
                'user' => $user,
                'form' => $form,
                'tfaConfig' => $temporary2faUser->getTotpAuthenticationConfiguration(),
                'qrCode' => $qrCode->build()->getDataUri(),
            ]
        );
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/users/{name}/2fa/confirm', name: 'user_2fa_confirm', methods: ['GET'])]
    public function confirmTwoFactorAuthAction(#[VarName('name')] User $user, #[CurrentUser] User $loggedUser, Request $req): Response
    {
        if ($user->getId() !== $loggedUser->getId()) {
            throw $this->createAccessDeniedException('You cannot change this user\'s two-factor authentication settings');
        }

        $backupCode = $req->getSession()->remove('backup_code');

        if (empty($backupCode)) {
            return $this->redirectToRoute('user_2fa_configure', ['name' => $user->getUsername()]);
        }

        return $this->render(
            'user/confirm_two_factor_auth.html.twig',
            ['user' => $user, 'backup_code' => $backupCode]
        );
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/users/{name}/2fa/disable', name: 'user_2fa_disable', methods: ['GET'])]
    public function disableTwoFactorAuthAction(Request $req, #[VarName('name')] User $user, #[CurrentUser] User $loggedUser, CsrfTokenManagerInterface $csrfTokenManager, TwoFactorAuthManager $authManager): Response
    {
        if (!$this->isGranted('ROLE_DISABLE_2FA') && $user->getId() !== $loggedUser->getId()) {
            throw $this->createAccessDeniedException('You cannot change this user\'s two-factor authentication settings');
        }

        if ($this->isCsrfTokenValid('disable_2fa', $req->query->getString('token'))) {
            $authManager->disableTwoFactorAuth($user, $user->getId() === $loggedUser->getId() ? 'Manually disabled' : 'Disabled on request from user');

            $this->addFlash('success', 'Two-factor authentication has been disabled.');

            return $this->redirectToRoute('user_2fa_configure', ['name' => $user->getUsername()]);
        }

        return $this->render(
            'user/disable_two_factor_auth.html.twig',
            ['user' => $user]
        );
    }
}
