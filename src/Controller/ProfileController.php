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
use App\Entity\Job;
use App\Entity\Package;
use App\Entity\User;
use App\Form\Type\ProfileFormType;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;
use App\Security\UserNotifier;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ProfileController extends Controller
{
    #[Route(path: '/profile/', name: 'my_profile')]
    public function myProfile(Request $req, FavoriteManager $favMgr, DownloadManager $dlMgr, #[CurrentUser] User $user): Response
    {
        $packages = $this->getUserPackages($req, $user);
        $lastGithubSync = $this->doctrine->getRepository(Job::class)->getLastGitHubSyncJob($user->getId());

        $data = [
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($favMgr, $dlMgr, $packages),
            'user' => $user,
            'githubSync' => $lastGithubSync,
        ];

        if (!count($packages)) {
            $data['deleteForm'] = $this->createFormBuilder([])->getForm()->createView();
        }

        return $this->render(
            'user/my_profile.html.twig',
            $data
        );
    }

    #[Route(path: '/users/{name}/', name: 'user_profile')]
    public function publicProfile(Request $req, #[VarName('name')] User $user, FavoriteManager $favMgr, DownloadManager $dlMgr, #[CurrentUser] ?User $loggedUser = null): Response
    {
        $packages = $this->getUserPackages($req, $user);

        $data = [
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($favMgr, $dlMgr, $packages),
            'user' => $user,
        ];

        if ($this->isGranted('ROLE_ANTISPAM')) {
            $data['spammerForm'] = $this->createFormBuilder([])->getForm()->createView();
        }
        if (!count($packages) && ($this->isGranted('ROLE_ADMIN') || $loggedUser?->getId() === $user->getId())) {
            $data['deleteForm'] = $this->createFormBuilder([])->getForm()->createView();
        }

        return $this->render(
            'user/public_profile.html.twig',
            $data
        );
    }

    #[Route(path: '/users/{name}/packages/', name: 'user_packages')]
    #[Route(path: '/users/{name}/packages.json', name: 'user_packages_json', defaults: ['_format' => 'json'])]
    public function packagesAction(Request $req, #[VarName('name')] User $user, FavoriteManager $favMgr, DownloadManager $dlMgr): Response
    {
        $packages = $this->getUserPackages($req, $user);

        if ($req->getRequestFormat() === 'json') {
            $packages->setMaxPerPage(50);
            $result = ['packages' => []];
            /** @var Package $pkg */
            foreach ($packages as $pkg) {
                $result['packages'][] = [
                    'name' => $pkg->getName(),
                    'description' => $pkg->getDescription(),
                    'repository' => $pkg->getRepository(),
                ];
            }

            if ($packages->hasNextPage()) {
                $result['next'] = $this->generateUrl('user_packages_json', ['name' => $user->getUsername(), 'page' => $packages->getCurrentPage() + 1], UrlGeneratorInterface::ABSOLUTE_URL);
            }

            return $this->json($result);
        }

        return $this->render(
            'user/packages.html.twig',
            [
                'packages' => $packages,
                'meta' => $this->getPackagesMetadata($favMgr, $dlMgr, $packages),
                'user' => $user,
            ]
        );
    }

    #[Route(path: '/profile/edit', name: 'edit_profile')]
    public function editAction(Request $request, UserNotifier $userNotifier): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('This user does not have access to this section.');
        }

        $oldEmail = $user->getEmail();
        $oldUsername = $user->getUsername();
        $form = $this->createForm(ProfileFormType::class, $user);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $diffs = array_filter([
                $oldEmail !== $user->getEmail() ? 'email ('.$oldEmail.' => '.$user->getEmail().')' : null,
                $oldUsername !== $user->getUsername() ? 'username ('.$oldUsername.' => '.$user->getUsername().')' : null,
            ]);

            if (!empty($diffs)) {
                $reason = sprintf('Your %s has been changed', implode(' and ', $diffs));

                if ($oldEmail !== $user->getEmail()) {
                    $userNotifier->notifyChange($oldEmail, $reason);
                    $user->resetPasswordRequest();
                }

                $userNotifier->notifyChange($user->getEmail(), $reason);
            }

            $this->getEM()->persist($user);
            $this->getEM()->flush();

            return $this->redirectToRoute('my_profile');
        }

        return $this->render('user/edit.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/profile/token/rotate', name: 'rotate_token', methods: ['POST'])]
    public function tokenRotateAction(Request $request, UserNotifier $userNotifier): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('This user does not have access to this section.');
        }
        $user->initializeApiToken();

        $userNotifier->notifyChange($user->getEmail(), 'Your API token has been rotated');

        $this->getEM()->persist($user);
        $this->getEM()->flush();

        return $this->redirectToRoute('my_profile');
    }

    /**
     * @return Pagerfanta<Package>
     */
    protected function getUserPackages(Request $req, User $user): Pagerfanta
    {
        $packages = $this->getEM()
            ->getRepository(Package::class)
            ->getFilteredQueryBuilder(['maintainer' => $user->getId()], true);

        $paginator = new Pagerfanta(new QueryAdapter($packages, true));
        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage(max(1, $req->query->getInt('page', 1)));

        return $paginator;
    }
}
