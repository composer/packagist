<?php declare(strict_types=1);

namespace App\Controller;

use App\Entity\Job;
use App\Entity\Package;
use App\Entity\User;
use App\Form\Type\ProfileFormType;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;

class ProfileController extends Controller
{
    /**
     * @Route("/profile/", name="my_profile")
     */
    public function myProfile(Request $req)
    {
        $user = $this->getUser();
        if (!is_object($user)) {
            throw $this->createAccessDeniedException('This user does not have access to this section.');
        }

        $packages = $this->getUserPackages($req, $user);
        $lastGithubSync = $this->doctrine->getRepository(Job::class)->getLastGitHubSyncJob($user->getId());

        $data = [
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($packages),
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
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     */
    public function publicProfile(Request $req, User $user)
    {
        $packages = $this->getUserPackages($req, $user);

        $data = [
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($packages),
            'user' => $user,
        ];

        if ($this->isGranted('ROLE_ANTISPAM')) {
            $data['spammerForm'] = $this->createFormBuilder([])->getForm()->createView();
        }
        if (!count($packages) && ($this->isGranted('ROLE_ADMIN') || ($this->getUser() && $this->getUser()->getId() === $user->getId()))) {
            $data['deleteForm'] = $this->createFormBuilder([])->getForm()->createView();
        }

        return $this->render(
            'user/public_profile.html.twig',
            $data
        );
    }

    /**
     * @Route("/users/{name}/packages/", name="user_packages")
     * @ParamConverter("user", options={"mapping": {"name": "username"}})
     */
    public function packagesAction(Request $req, User $user)
    {
        $packages = $this->getUserPackages($req, $user);

        return $this->render(
            'user/packages.html.twig',
            [
                'packages' => $packages,
                'meta' => $this->getPackagesMetadata($packages),
                'user' => $user,
            ]
        );
    }

    /**
     * @Route("/profile/edit", name="edit_profile")
     */
    public function editAction(Request $request)
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

    protected function getUserPackages(Request $req, User $user): Pagerfanta
    {
        $packages = $this->getEM()
            ->getRepository(Package::class)
            ->getFilteredQueryBuilder(['maintainer' => $user->getId()], true);

        $paginator = new Pagerfanta(new QueryAdapter($packages, true));
        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage(max(1, (int) $req->query->get('page', 1)));

        return $paginator;
    }
}
