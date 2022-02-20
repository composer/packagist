<?php declare(strict_types=1);

namespace App\Controller;

use App\Attribute\VarName;
use App\Entity\Job;
use App\Entity\Package;
use App\Entity\User;
use App\Form\Type\ProfileFormType;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ProfileController extends Controller
{
    /**
     * @Route("/profile/", name="my_profile")
     */
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

    /**
     * @Route("/users/{name}/", name="user_profile")
     */
    public function publicProfile(Request $req, #[VarName('name')] User $user, FavoriteManager $favMgr, DownloadManager $dlMgr): Response
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
        $isLoggedInUser = ($loggedUser = $this->getUser()) && $loggedUser instanceof User && $loggedUser->getId() === $user->getId();
        if (!count($packages) && ($this->isGranted('ROLE_ADMIN') || $isLoggedInUser)) {
            $data['deleteForm'] = $this->createFormBuilder([])->getForm()->createView();
        }

        return $this->render(
            'user/public_profile.html.twig',
            $data
        );
    }

    /**
     * @Route("/users/{name}/packages/", name="user_packages")
     * @Route("/users/{name}/packages.json", name="user_packages_json", defaults={"_format": "json"})
     */
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

    /**
     * @Route("/profile/edit", name="edit_profile")
     */
    public function editAction(Request $request): Response
    {
        $user = $this->getUser();
        if (!is_object($user)) {
            throw $this->createAccessDeniedException('This user does not have access to this section.');
        }

        $form = $this->createForm(ProfileFormType::class, $user);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->getEM()->persist($user);
            $this->getEM()->flush();

            return $this->redirectToRoute('my_profile');
        }

        return $this->render('user/edit.html.twig', array(
            'form' => $form->createView(),
        ));
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
