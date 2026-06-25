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
use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\TemporaryTwoFactorUser;
use App\Entity\User;
use App\Entity\UserFreezeReason;
use App\Form\Model\EnableTwoFactorRequest;
use App\Form\Model\FreezeRequest;
use App\Form\Model\UnfreezeRequest;
use App\Form\Type\EnableTwoFactorAuthType;
use App\Form\Type\FreezeType;
use App\Form\Type\UnfreezeType;
use App\Model\FavoriteManager;
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
    public function __construct(private Scheduler $scheduler)
    {
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

    #[Route(path: '/users/{name}/freeze', name: 'freeze_user', methods: ['POST'])]
    public function freezeUserAction(Request $req, #[VarName('name')] User $user, #[CurrentUser] User $adminUser): RedirectResponse
    {
        // Anti-spam moderators may freeze accounts as spam; ROLE_DISABLE_USERS may use any reason.
        if (!$this->isGranted('ROLE_ANTISPAM') && !$this->isGranted('ROLE_DISABLE_USERS')) {
            throw $this->createAccessDeniedException('You cannot freeze accounts');
        }

        if ($user->getId() === $adminUser->getId()) {
            $this->addFlash('error', 'You cannot freeze your own account.');

            return $this->redirectToRoute('user_profile', ['name' => $user->getUsername()]);
        }

        // The role-limited choices below are what enforce that, e.g., an anti-spam moderator can
        // only pick the spam reason — the form rejects anything outside the allowed set.
        $packageReasons = $this->allowedPackageFreezeReasons();
        $freezeRequest = new FreezeRequest();
        $form = $this->createForm(FreezeType::class, $freezeRequest, [
            'account_reasons' => $this->allowedAccountFreezeReasons(),
            'package_reasons' => $packageReasons,
        ]);
        $form->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            $reason = $freezeRequest->reason;
            if ($reason === null) {
                return $this->redirectToRoute('user_profile', ['name' => $user->getUsername()]);
            }

            $user->freeze($reason);

            $em = $this->getEM();
            $em->persist(AuditRecord::userFrozen($user, $adminUser, $reason, $freezeRequest->reasonText, $freezeRequest->internalReason));
            $em->persist($user);
            $em->flush();

            if ($freezeRequest->freezePackages && $freezeRequest->packageFreezeReason !== null && $packageReasons !== []) {
                $purge = $freezeRequest->purgePackages && $freezeRequest->packageFreezeReason->suppressesPackage();
                $this->freezeUserPackages($user, $adminUser, $freezeRequest->packageFreezeReason, $purge);
            }

            $this->addFlash('success', $user->getUsername().' has been frozen.');
        }

        return $this->redirectToRoute('user_profile', ['name' => $user->getUsername()]);
    }

    #[Route(path: '/users/{name}/unfreeze', name: 'unfreeze_user', methods: ['POST'])]
    public function unfreezeUserAction(Request $req, #[VarName('name')] User $user, #[CurrentUser] User $adminUser): RedirectResponse
    {
        // ROLE_DISABLE_USERS may unfreeze any reason; anti-spam moderators only spam freezes.
        $mayUnfreeze = $this->isGranted('ROLE_DISABLE_USERS')
            || ($this->isGranted('ROLE_ANTISPAM') && $user->getFreezeReason() === UserFreezeReason::Spam);
        if (!$mayUnfreeze) {
            throw $this->createAccessDeniedException('You cannot unfreeze this account');
        }

        $unfreezeRequest = new UnfreezeRequest();
        $form = $this->createForm(UnfreezeType::class, $unfreezeRequest);
        $form->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$user->isFrozen()) {
                $this->addFlash('warning', 'This account is not frozen.');

                return $this->redirectToRoute('user_profile', ['name' => $user->getUsername()]);
            }

            $user->unfreeze();

            $em = $this->getEM();
            $em->persist(AuditRecord::userUnfrozen($user, $adminUser, $unfreezeRequest->reasonText, $unfreezeRequest->internalReason));
            $em->persist($user);
            $em->flush();

            $this->addFlash('success', $user->getUsername().' has been unfrozen.');
        }

        return $this->redirectToRoute('user_profile', ['name' => $user->getUsername()]);
    }

    /**
     * @return list<UserFreezeReason>
     */
    private function allowedAccountFreezeReasons(): array
    {
        return $this->isGranted('ROLE_DISABLE_USERS') ? UserFreezeReason::cases() : [UserFreezeReason::Spam];
    }

    /**
     * @return list<PackageFreezeReason>
     */
    private function allowedPackageFreezeReasons(): array
    {
        if ($this->isGranted('ROLE_DISABLE_PACKAGES')) {
            return [PackageFreezeReason::Spam, PackageFreezeReason::Malware, PackageFreezeReason::Temporary];
        }
        if ($this->isGranted('ROLE_ANTISPAM')) {
            return [PackageFreezeReason::Spam];
        }

        return [];
    }

    /**
     * Freezes the user's packages with the chosen reason immediately (so suppressed ones stop being
     * served right away). When purging, the heavy per-package work — soft-deleting versions and
     * removing published artifacts — is offloaded to a background job per package.
     */
    private function freezeUserPackages(User $user, User $actor, PackageFreezeReason $reason, bool $purge): void
    {
        $em = $this->getEM();

        // Suppressed reasons (spam/malware) also drop the package from the search index immediately.
        if ($reason->suppressesPackage()) {
            $sql = 'UPDATE package p JOIN maintainers_packages mp ON mp.package_id = p.id SET frozen = :reason, indexedAt = NULL WHERE mp.user_id = :userId';
        } else {
            $sql = 'UPDATE package p JOIN maintainers_packages mp ON mp.package_id = p.id SET frozen = :reason WHERE mp.user_id = :userId';
        }
        $em->getConnection()->executeStatement($sql, ['reason' => $reason->value, 'userId' => $user->getId()]);

        if (!$purge) {
            return;
        }

        $qb = $em->getRepository(Package::class)->createQueryBuilder('p');
        $packages = $qb->leftJoin('p.maintainers', 'm')
            ->where('m.id = :maintainer')
            ->setParameter('maintainer', $user->getId())
            ->getQuery()->getResult();

        foreach ($packages as $package) {
            $this->scheduler->schedulePackagePurge($package, $package->getName(), $actor->getId());
        }
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
    #[Route(path: '/users/{name}/favorites/{package}', name: 'user_remove_fav', defaults: ['_format' => 'json'], requirements: ['package' => Package::PACKAGE_NAME_REGEX], methods: ['DELETE'])]
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
            $authManager->enableTwoFactorAuth($user, $loggedUser, $secret);
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
            $authManager->disableTwoFactorAuth($user, $loggedUser, $user->getId() === $loggedUser->getId() ? 'Manually disabled' : 'Disabled on request from user');

            $this->addFlash('success', 'Two-factor authentication has been disabled.');

            return $this->redirectToRoute('user_2fa_configure', ['name' => $user->getUsername()]);
        }

        return $this->render(
            'user/disable_two_factor_auth.html.twig',
            ['user' => $user]
        );
    }
}
