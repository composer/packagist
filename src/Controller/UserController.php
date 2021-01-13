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

use Doctrine\ORM\NoResultException;
use FOS\UserBundle\Model\UserInterface;
use FOS\UserBundle\Model\UserManagerInterface;
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
     * @Template()
     * @Route("/users/{name}/packages/", name="user_packages")
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     */
    public function packagesAction(Request $req, User $user)
    {
        $packages = $this->getUserPackages($req, $user);

        return array(
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($packages),
            'user' => $user,
        );
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

            return $this->redirectToRoute('fos_user_profile_show');
        }

        if (!$user->getGithubScope()) {
            $this->addFlash('error', 'Please log out and log in with GitHub again to make sure the correct GitHub permissions are granted.');

            return $this->redirectToRoute('fos_user_profile_show');
        }

        $this->scheduler->scheduleUserScopeMigration($user->getId(), '', $user->getGithubScope());

        sleep(5);

        $this->addFlash('success', 'User sync scheduled. It might take a few seconds to run through, make sure you refresh then to check if any packages still need sync.');

        return $this->redirectToRoute('fos_user_profile_show');
    }

    /**
     * @Route("/spammers/{name}/", name="mark_spammer", methods={"POST"})
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     */
    public function markSpammerAction(Request $req, User $user, UserManagerInterface $userManager)
    {
        if (!$this->isGranted('ROLE_ANTISPAM')) {
            throw new AccessDeniedException('This user can not mark others as spammers');
        }

        $form = $this->createFormBuilder(array())->getForm();

        $form->submit($req->request->get('form'));
        if ($form->isSubmitted() && $form->isValid()) {
            $user->addRole('ROLE_SPAMMER');
            $user->setEnabled(false);
            $userManager->updateUser($user);
            $doctrine = $this->doctrine;

            $doctrine->getConnection()->executeUpdate(
                'UPDATE package p JOIN maintainers_packages mp ON mp.package_id = p.id
                 SET abandoned = 1, replacementPackage = "spam/spam", suspect = "spam", indexedAt = NULL, dumpedAt = "2100-01-01 00:00:00"
                 WHERE mp.user_id = :userId',
                ['userId' => $user->getId()]
            );

            /** @var VersionRepository $versionRepo */
            $versionRepo = $doctrine->getRepository(Version::class);
            $packages = $doctrine
                ->getRepository(Package::class)
                ->getFilteredQueryBuilder(array('maintainer' => $user->getId()), true)
                ->getQuery()->getResult();

            foreach ($packages as $package) {
                foreach ($package->getVersions() as $version) {
                    $versionRepo->remove($version);
                }

                $this->providerManager->deletePackage($package);
            }

            $this->doctrine->getManager()->flush();

            $this->addFlash('success', $user->getUsername().' has been marked as a spammer');
        }

        return $this->redirect(
            $this->generateUrl("user_profile", array("name" => $user->getUsername()))
        );
    }

    /**
     * @param Request $req
     * @return Response
     */
    public function viewProfileAction(Request $req)
    {
        $user = $this->getUser();
        if (!is_object($user) || !$user instanceof UserInterface) {
            throw new AccessDeniedException('This user does not have access to this section.');
        }

        $packages = $this->getUserPackages($req, $user);
        $lastGithubSync = $this->doctrine->getRepository(Job::class)->getLastGitHubSyncJob($user->getId());

        $data = array(
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($packages),
            'user' => $user,
            'githubSync' => $lastGithubSync,
        );

        if (!count($packages)) {
            $data['deleteForm'] = $this->createFormBuilder(array())->getForm()->createView();
        }

        return $this->render(
            '@FOSUser/Profile/show.html.twig',
            $data
        );
    }

    /**
     * @Template()
     * @Route("/users/{name}/", name="user_profile")
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     */
    public function profileAction(Request $req, User $user)
    {
        $packages = $this->getUserPackages($req, $user);

        $data = array(
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($packages),
            'user' => $user,
        );

        if ($this->isGranted('ROLE_ANTISPAM')) {
            $data['spammerForm'] = $this->createFormBuilder(array())->getForm()->createView();
        }
        if (!count($packages) && ($this->isGranted('ROLE_ADMIN') || ($this->getUser() && $this->getUser()->getId() === $user->getId()))) {
            $data['deleteForm'] = $this->createFormBuilder(array())->getForm()->createView();
        }

        return $data;
    }

    /**
     * @Route("/oauth/github/disconnect", name="user_github_disconnect")
     */
    public function disconnectGitHubAction(Request $req, CsrfTokenManagerInterface $csrfTokenManager)
    {
        $user = $this->getUser();
        $token = $csrfTokenManager->getToken('unlink_github')->getValue();
        if (!hash_equals($token, $req->query->get('token', '')) || !$user) {
            throw new AccessDeniedException('Invalid CSRF token');
        }

        if ($user->getGithubId()) {
            $user->setGithubId(null);
            $user->setGithubToken(null);
            $user->setGithubScope(null);
            $this->doctrine->getManager()->flush();
        }

        return $this->redirectToRoute('fos_user_profile_edit');
    }

    /**
     * @Template()
     * @Route("/users/{name}/favorites/", name="user_favorites", methods={"GET"})
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     */
    public function favoritesAction(Request $req, User $user, LoggerInterface $logger, RedisClient $redis)
    {
        try {
            if (!$redis->isConnected()) {
                $redis->connect();
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Could not connect to the Redis database.');
            $logger->notice($e->getMessage(), array('exception' => $e));

            return array('user' => $user, 'packages' => array());
        }

        $paginator = new Pagerfanta(
            new RedisAdapter($this->favoriteManager, $user, 'getFavorites', 'getFavoriteCount')
        );

        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage(max(1, (int) $req->query->get('page', 1)));

        return array('packages' => $paginator, 'user' => $user);
    }

    /**
     * @Route("/users/{name}/favorites/", name="user_add_fav", defaults={"_format" = "json"}, methods={"POST"})
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     */
    public function postFavoriteAction(Request $req, User $user)
    {
        if (!$this->getUser() || $user->getId() !== $this->getUser()->getId()) {
            throw new AccessDeniedException('You can only change your own favorites');
        }

        $package = $req->request->get('package');
        try {
            $package = $this->doctrine
                ->getRepository(Package::class)
                ->findOneByName($package);
        } catch (NoResultException $e) {
            throw new NotFoundHttpException('The given package "'.$package.'" was not found.');
        }

        $this->favoriteManager->markFavorite($user, $package);

        return new Response('{"status": "success"}', 201);
    }

    /**
     * @Route("/users/{name}/favorites/{package}", name="user_remove_fav", defaults={"_format" = "json"}, requirements={"package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}, methods={"DELETE"})
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     * @ParamConverter("package", options={"mapping": {"package": "name"}})
     */
    public function deleteFavoriteAction(User $user, Package $package)
    {
        if (!$this->getUser() || $user->getId() !== $this->getUser()->getId()) {
            throw new AccessDeniedException('You can only change your own favorites');
        }

        $this->favoriteManager->removeFavorite($user, $package);

        return new Response('{"status": "success"}', 204);
    }

    /**
     * @Route("/users/{name}/delete", name="user_delete", defaults={"_format" = "json"}, methods={"POST"})
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     */
    public function deleteUserAction(User $user, Request $req, TokenStorageInterface $storage)
    {
        if (!($this->isGranted('ROLE_ADMIN') || ($this->getUser() && $user->getId() === $this->getUser()->getId()))) {
            throw new AccessDeniedException('You cannot delete this user');
        }

        if (count($user->getPackages()) > 0) {
            throw new AccessDeniedException('The user has packages so it can not be deleted');
        }

        $form = $this->createFormBuilder(array())->getForm();

        $form->submit($req->request->get('form'));
        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->doctrine->getManager();
            $em->remove($user);
            $em->flush();

            $storage->setToken(null);

            return $this->redirectToRoute('home');
        }

        return $this->redirectToRoute('user_profile', ['name' => $user->getName()]);
    }

    /**
     * @Template()
     * @Route("/users/{name}/2fa/", name="user_2fa_configure", methods={"GET"})
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     */
    public function configureTwoFactorAuthAction(User $user, Request $req)
    {
        if (!($this->isGranted('ROLE_DISABLE_2FA') || ($this->getUser() && $user->getId() === $this->getUser()->getId()))) {
            throw new AccessDeniedException('You cannot change this user\'s two-factor authentication settings');
        }

        if ($user->getId() === $this->getUser()->getId()) {
            $backupCode = $req->getSession()->remove('backup_code');
        }

        return array('user' => $user, 'backup_code' => $backupCode ?? null);
    }

    /**
     * @Template()
     * @Route("/users/{name}/2fa/enable", name="user_2fa_enable", methods={"GET", "POST"})
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
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

                return $this->redirectToRoute('user_2fa_confirm', array('name' => $user->getUsername()));
            }
        }

        return array('user' => $user, 'provisioningUri' => $authenticator->getQRContent($user), 'secret' => $enableRequest->getSecret(), 'form' => $form->createView());
    }

    /**
     * @Template()
     * @Route("/users/{name}/2fa/confirm", name="user_2fa_confirm", methods={"GET"})
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
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

        return array('user' => $user, 'backup_code' => $backupCode);
    }

    /**
     * @Template()
     * @Route("/users/{name}/2fa/disable", name="user_2fa_disable", methods={"GET"})
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
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

            return $this->redirectToRoute('user_2fa_configure', array('name' => $user->getUsername()));
        }

        return array('user' => $user);
    }

    /**
     * @param Request $req
     * @param User $user
     * @return Pagerfanta
     */
    protected function getUserPackages($req, $user)
    {
        $packages = $this->doctrine
            ->getRepository(Package::class)
            ->getFilteredQueryBuilder(array('maintainer' => $user->getId()), true);

        $paginator = new Pagerfanta(new QueryAdapter($packages, true));
        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage(max(1, (int) $req->query->get('page', 1)));

        return $paginator;
    }
}
