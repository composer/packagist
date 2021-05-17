<?php

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

use App\Model\FavoriteManager;
use Doctrine\ORM\NoResultException;
use App\Entity\Job;
use App\Entity\Package;
use App\Entity\Version;
use App\Entity\User;
use App\Entity\VersionRepository;
use App\Form\Model\EnableTwoFactorRequest;
use App\Form\Type\EnableTwoFactorAuthType;
use App\Model\ProviderManager;
use App\Model\RedisAdapter;
use App\Security\TwoFactorAuthManager;
use App\Service\Scheduler;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Predis\Client as RedisClient;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;
use Endroid\QrCode\Writer\PngWriter;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UserController extends Controller
{
    private ProviderManager $providerManager;
    private Scheduler $scheduler;

    public function __construct(ProviderManager $providerManager, Scheduler $scheduler)
    {
        $this->providerManager = $providerManager;
        $this->scheduler = $scheduler;
    }

    /**
     * @Route("/trigger-github-sync/", name="user_github_sync")
     */
    public function triggerGitHubSyncAction()
    {
        $user = $this->getUser();
        if (!$user) {
            throw new AccessDeniedException();
        }

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

    /**
     * @Route("/spammers/{name}/", name="mark_spammer", methods={"POST"})
     * @ParamConverter("user", options={"mapping": {"name": "usernameCanonical"}})
     */
    public function markSpammerAction(Request $req, User $user)
    {
        if (!$this->isGranted('ROLE_ANTISPAM')) {
            throw new AccessDeniedException('This user can not mark others as spammers');
        }

        $form = $this->createFormBuilder([])->getForm();

        $form->submit($req->request->all('form'));
        if ($form->isSubmitted() && $form->isValid()) {
            $user->addRole('ROLE_SPAMMER');
            $user->setEnabled(false);

            $em = $this->getEM();

            $em->getConnection()->executeStatement(
                'UPDATE package p JOIN maintainers_packages mp ON mp.package_id = p.id
                 SET abandoned = 1, replacementPackage = "spam/spam", suspect = "spam", indexedAt = NULL, dumpedAt = "2100-01-01 00:00:00"
                 WHERE mp.user_id = :userId',
                ['userId' => $user->getId()]
            );

            /** @var VersionRepository $versionRepo */
            $versionRepo = $em->getRepository(Version::class);
            $packages = $em
                ->getRepository(Package::class)
                ->getFilteredQueryBuilder(['maintainer' => $user->getId()], true)
                ->getQuery()->getResult();

            foreach ($packages as $package) {
                foreach ($package->getVersions() as $version) {
                    $versionRepo->remove($version);
                }

                $this->providerManager->deletePackage($package);
            }

            $this->getEM()->flush();

            $this->addFlash('success', $user->getUsername().' has been marked as a spammer');
        }

        return $this->redirect(
            $this->generateUrl("user_profile", ["name" => $user->getUsername()])
        );
    }

    /**
     * @Template()
     * @Route("/users/{name}/favorites/", name="user_favorites", methods={"GET"})
     * @ParamConverter("user", options={"mapping": {"name": "usernameCanonical"}})
     */
    public function favoritesAction(Request $req, User $user, LoggerInterface $logger, RedisClient $redis, FavoriteManager $favoriteManager)
    {
        try {
            if (!$redis->isConnected()) {
                $redis->connect();
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Could not connect to the Redis database.');
            $logger->notice($e->getMessage(), ['exception' => $e]);

            return ['user' => $user, 'packages' => []];
        }

        $paginator = new Pagerfanta(
            new RedisAdapter($favoriteManager, $user, 'getFavorites', 'getFavoriteCount')
        );

        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage(max(1, (int) $req->query->get('page', 1)));

        return ['packages' => $paginator, 'user' => $user];
    }

    /**
     * @Route("/users/{name}/favorites/", name="user_add_fav", defaults={"_format" = "json"}, methods={"POST"})
     * @ParamConverter("user", options={"mapping": {"name": "usernameCanonical"}})
     */
    public function postFavoriteAction(Request $req, User $user, FavoriteManager $favoriteManager)
    {
        if (!$this->getUser() || $user->getId() !== $this->getUser()->getId()) {
            throw new AccessDeniedException('You can only change your own favorites');
        }

        $package = $req->request->get('package');
        try {
            $package = $this->getEM()
                ->getRepository(Package::class)
                ->findOneBy(['name' => $package]);
        } catch (NoResultException $e) {
            throw new NotFoundHttpException('The given package "'.$package.'" was not found.');
        }

        $favoriteManager->markFavorite($user, $package);

        return new Response('{"status": "success"}', 201);
    }

    /**
     * @Route("/users/{name}/favorites/{package}", name="user_remove_fav", defaults={"_format" = "json"}, requirements={"package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}, methods={"DELETE"})
     * @ParamConverter("user", options={"mapping": {"name": "usernameCanonical"}})
     * @ParamConverter("package", options={"mapping": {"package": "name"}})
     */
    public function deleteFavoriteAction(User $user, Package $package, FavoriteManager $favoriteManager)
    {
        if (!$this->getUser() || $user->getId() !== $this->getUser()->getId()) {
            throw new AccessDeniedException('You can only change your own favorites');
        }

        $favoriteManager->removeFavorite($user, $package);

        return new Response('{"status": "success"}', 204);
    }

    /**
     * @Route("/users/{name}/delete", name="user_delete", methods={"POST"})
     * @ParamConverter("user", options={"mapping": {"name": "usernameCanonical"}})
     */
    public function deleteUserAction(User $user, Request $req, TokenStorageInterface $storage, EventDispatcherInterface $mainEventDispatcher)
    {
        if (!($this->isGranted('ROLE_ADMIN') || ($this->getUser() && $user->getId() === $this->getUser()->getId()))) {
            throw new AccessDeniedException('You cannot delete this user');
        }

        if (count($user->getPackages()) > 0) {
            throw new AccessDeniedException('The user has packages so it can not be deleted');
        }

        $form = $this->createFormBuilder([])->getForm();

        $form->submit($req->request->all('form'));
        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getEM();
            $em->remove($user);
            $em->flush();

            // programmatic logout
            $logoutEvent = new LogoutEvent($req, $storage->getToken());
            $mainEventDispatcher->dispatch($logoutEvent);
            $response = $logoutEvent->getResponse();
            if (!$response instanceof Response) {
                throw new \RuntimeException('No logout listener set the Response, make sure at least the DefaultLogoutListener is registered.');
            }
            $storage->setToken(null);

            return $this->redirectToRoute('home');
        }

        return $this->redirectToRoute('user_profile', ['name' => $user->getUsername()]);
    }

    /**
     * @Template()
     * @Route("/users/{name}/2fa/", name="user_2fa_configure", methods={"GET"})
     * @ParamConverter("user", options={"mapping": {"name": "usernameCanonical"}})
     */
    public function configureTwoFactorAuthAction(User $user, Request $req)
    {
        if (!($this->isGranted('ROLE_DISABLE_2FA') || ($this->getUser() && $user->getId() === $this->getUser()->getId()))) {
            throw new AccessDeniedException('You cannot change this user\'s two-factor authentication settings');
        }

        if ($user->getId() === $this->getUser()->getId()) {
            $backupCode = $req->getSession()->remove('backup_code');
        }

        return ['user' => $user, 'backup_code' => $backupCode ?? null];
    }

    /**
     * @Template()
     * @Route("/users/{name}/2fa/enable", name="user_2fa_enable", methods={"GET", "POST"})
     * @ParamConverter("user", options={"mapping": {"name": "usernameCanonical"}})
     */
    public function enableTwoFactorAuthAction(Request $req, User $user, TotpAuthenticatorInterface $authenticator, TwoFactorAuthManager $authManager)
    {
        if (!$this->getUser() || $user->getId() !== $this->getUser()->getId()) {
            throw new AccessDeniedException('You cannot change this user\'s two-factor authentication settings');
        }

        $enableRequest = new EnableTwoFactorRequest($authenticator->generateSecret());
        $form = $this->createForm(EnableTwoFactorAuthType::class, $enableRequest);
        $form->handleRequest($req);

        // Temporarily store this code on the user, as we'll need it there to generate the
        // QR code and to check the confirmation code.  We won't actually save this change
        // until we've confirmed the code
        $user->setTotpSecret($enableRequest->getSecret());

        if ($form->isSubmitted()) {
            // Validate the code using the secret that was submitted in the form
            if (!$authenticator->checkCode($user, $enableRequest->getCode())) {
                $form->get('code')->addError(new FormError('Invalid authenticator code'));
            }

            if ($form->isValid()) {
                $authManager->enableTwoFactorAuth($user, $enableRequest->getSecret());
                $backupCode = $authManager->generateAndSaveNewBackupCode($user);

                $this->addFlash('success', 'Two-factor authentication has been enabled.');
                $req->getSession()->set('backup_code', $backupCode);

                return $this->redirectToRoute('user_2fa_confirm', ['name' => $user->getUsername()]);
            }
        }

        $qrContent = $authenticator->getQRContent($user);

        $qrCode = Builder::create()
            ->writer(new PngWriter())
            ->writerOptions([])
            ->data($qrContent)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
            ->size(200)
            ->margin(0)
            ->roundBlockSizeMode(new RoundBlockSizeModeMargin())
            ->build();

        return [
            'user' => $user,
            'secret' => $enableRequest->getSecret(),
            'form' => $form->createView(),
            'qrCode' => $qrCode->getDataUri(),
        ];
    }

    /**
     * @Template()
     * @Route("/users/{name}/2fa/confirm", name="user_2fa_confirm", methods={"GET"})
     * @ParamConverter("user", options={"mapping": {"name": "usernameCanonical"}})
     */
    public function confirmTwoFactorAuthAction(User $user, Request $req)
    {
        if (!$this->getUser() || $user->getId() !== $this->getUser()->getId()) {
            throw new AccessDeniedException('You cannot change this user\'s two-factor authentication settings');
        }

        $backupCode = $req->getSession()->remove('backup_code');

        if (empty($backupCode)) {
            return $this->redirectToRoute('user_2fa_configure', ['name' => $user->getUsername()]);
        }

        return ['user' => $user, 'backup_code' => $backupCode];
    }

    /**
     * @Template()
     * @Route("/users/{name}/2fa/disable", name="user_2fa_disable", methods={"GET"})
     * @ParamConverter("user", options={"mapping": {"name": "usernameCanonical"}})
     */
    public function disableTwoFactorAuthAction(Request $req, User $user, CsrfTokenManagerInterface $csrfTokenManager, TwoFactorAuthManager $authManager)
    {
        if (!($this->isGranted('ROLE_DISABLE_2FA') || ($this->getUser() && $user->getId() === $this->getUser()->getId()))) {
            throw new AccessDeniedException('You cannot change this user\'s two-factor authentication settings');
        }

        $token = $csrfTokenManager->getToken('disable_2fa')->getValue();
        if (hash_equals($token, $req->query->get('token', ''))) {
            $authManager->disableTwoFactorAuth($user, 'Manually disabled');

            $this->addFlash('success', 'Two-factor authentication has been disabled.');

            return $this->redirectToRoute('user_2fa_configure', ['name' => $user->getUsername()]);
        }

        return ['user' => $user];
    }
}
