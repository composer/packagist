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

use App\Entity\Organization;
use App\Entity\OrganizationRepository;
use App\Entity\OrganizationTeam;
use App\Entity\OrganizationTeamMember;
use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\OrganizationTeamRepository;
use App\Entity\User;
use App\Entity\UserRepository;
use App\Form\Model\AddTeamMemberRequest;
use App\Form\Model\OrganizationDetailsRequest;
use App\Form\Model\TeamRequest;
use App\Form\Type\AddTeamMemberType;
use App\Form\Type\OrganizationDetailsType;
use App\Form\Type\TeamType;
use App\Organization\Domain\Exception\OrganizationException;
use App\Organization\Domain\Slug;
use App\Organization\OrganizationManager;
use App\Organization\OrganizationMembershipManager;
use App\Security\Voter\OrganizationActions;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

#[IsGranted('ROLE_ADMIN_ORGS')]
class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationManager $organizationManager,
        private readonly OrganizationMembershipManager $membershipManager,
        private readonly OrganizationRepository $organizationRepo,
        private readonly OrganizationTeamRepository $teams,
        private readonly OrganizationTeamMemberRepository $teamMembers,
        private readonly UserRepository $users,
    ) {
    }

    #[Route(path: '/organizations', name: 'organization_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): Response
    {
        // Currently organizations are admin-only groundwork: every actor here holds
        // ROLE_ADMIN_ORGS and sees only the organizations they own.
        return $this->render('organization/list.html.twig', [
            'organizations' => $this->organizationRepo->findByOwner($user),
        ]);
    }

    #[Route(path: '/organizations/{organization}', name: 'organization_show', methods: ['GET'], requirements: ['organization' => Slug::PATTERN])]
    public function show(Organization $organization): Response
    {
        return $this->render('organization/show.html.twig', [
            'organization' => $organization,
        ]);
    }

    #[Route(path: '/organizations/{organization}/settings', name: 'organization_settings', methods: ['GET', 'POST'], requirements: ['organization' => Slug::PATTERN])]
    public function settings(Request $request, Organization $organization, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(OrganizationActions::Edit->value, $organization);

        if ($redirect = $this->require2fa($user)) {
            return $redirect;
        }

        $editRequest = new OrganizationDetailsRequest();
        $editRequest->slug = $organization->slug;
        $editRequest->displayName = $organization->displayName;

        $form = $this->createForm(OrganizationDetailsType::class, $editRequest, ['include_rename_notice' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->organizationManager->edit(
                    $organization,
                    $user,
                    $editRequest->slug,
                    $editRequest->displayName,
                    $request->getClientIp(),
                );

                $this->addFlash('success', 'Organization settings edited.');

                return $this->redirectToRoute('organization_settings', ['organization' => $organization->slug]);
            } catch (OrganizationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('organization/settings.html.twig', [
            'organization' => $organization,
            'form' => $form->createView(),
        ]);
    }

    #[Route(path: '/organizations/{organization}/teams', name: 'organization_teams', methods: ['GET'], requirements: ['organization' => Slug::PATTERN])]
    public function teams(Organization $organization): Response
    {
        // Any org member (or admin) may view the teams; management is owner-only per-action.
        $this->denyAccessUnlessGranted(OrganizationActions::ViewTeams->value, $organization);

        $addMemberForm = $this->createForm(AddTeamMemberType::class, new AddTeamMemberRequest());

        return $this->render('organization/teams.html.twig', [
            'organization' => $organization,
            'addMemberForm' => $addMemberForm->createView(),
            'teams' => $this->teamsView($organization),
        ]);
    }

    #[Route(path: '/organizations/{organization}/teams/create', name: 'organization_team_create', methods: ['GET', 'POST'], requirements: ['organization' => Slug::PATTERN])]
    public function createTeam(Request $request, Organization $organization, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(OrganizationActions::CreateTeam->value, $organization);

        if ($redirect = $this->require2fa($user)) {
            return $redirect;
        }

        $teamRequest = new TeamRequest();
        $form = $this->createForm(TeamType::class, $teamRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->membershipManager->createTeam($organization, $user, $teamRequest->name, $request->getClientIp());
                $this->addFlash('success', sprintf('Team "%s" created.', $teamRequest->name));

                return $this->redirectToRoute('organization_teams', ['organization' => $organization->slug]);
            } catch (OrganizationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('organization/team_create.html.twig', [
            'organization' => $organization,
            'form' => $form->createView(),
        ]);
    }

    #[Route(path: '/organizations/{organization}/teams/{teamId}/rename', name: 'organization_team_rename', methods: ['GET', 'POST'], requirements: ['organization' => Slug::PATTERN, 'teamId' => Requirement::ULID])]
    public function renameTeam(Request $request, Organization $organization, string $teamId, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(OrganizationActions::RenameTeam->value, $organization);

        if ($redirect = $this->require2fa($user)) {
            return $redirect;
        }

        $team = $this->teams->findOneByOrgAndTeamId($organization->id, $this->teamId($teamId));
        if ($team === null || $team->isSystem()) {
            throw new NotFoundHttpException('Team not found.');
        }

        $teamRequest = new TeamRequest();
        $teamRequest->name = $team->name;

        $form = $this->createForm(TeamType::class, $teamRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->membershipManager->renameTeam($organization, $user, $team->teamId, $teamRequest->name, $request->getClientIp());
                $this->addFlash('success', 'Team renamed.');

                return $this->redirectToRoute('organization_teams', ['organization' => $organization->slug]);
            } catch (OrganizationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('organization/team_rename.html.twig', [
            'organization' => $organization,
            'team' => $team,
            'form' => $form->createView(),
        ]);
    }

    #[Route(path: '/organizations/{organization}/teams/{teamId}/delete', name: 'organization_team_delete', methods: ['POST'], requirements: ['organization' => Slug::PATTERN, 'teamId' => Requirement::ULID])]
    public function deleteTeam(Request $request, Organization $organization, string $teamId, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(OrganizationActions::DeleteTeam->value, $organization);
        $this->assertCsrf($request, 'org_team_delete_'.$teamId);

        try {
            $this->membershipManager->deleteTeam($organization, $user, $this->teamId($teamId), $request->getClientIp());
            $this->addFlash('success', 'Team deleted.');
        } catch (OrganizationException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('organization_teams', ['organization' => $organization->slug]);
    }

    #[Route(path: '/organizations/{organization}/teams/{teamId}/members', name: 'organization_team_member_add', methods: ['POST'], requirements: ['organization' => Slug::PATTERN, 'teamId' => Requirement::ULID])]
    public function addTeamMember(Request $request, Organization $organization, string $teamId, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(OrganizationActions::AddTeamMember->value, $organization);

        $addRequest = new AddTeamMemberRequest();
        $form = $this->createForm(AddTeamMemberType::class, $addRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $target = $this->users->findOneByUsernameOrEmail($addRequest->username);
            if ($target === null) {
                $this->addFlash('error', sprintf('No user "%s" was found.', $addRequest->username));
            } else {
                try {
                    $this->membershipManager->addTeamMember($organization, $user, $this->teamId($teamId), $target->getId(), $request->getClientIp());
                    $this->addFlash('success', sprintf('%s added to the team.', $target->getUsername()));
                } catch (OrganizationException $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }
        }

        return $this->redirectToRoute('organization_teams', ['organization' => $organization->slug]);
    }

    #[Route(path: '/organizations/{organization}/teams/{teamId}/members/{userId}/remove', name: 'organization_team_member_remove', methods: ['POST'], requirements: ['organization' => Slug::PATTERN, 'teamId' => Requirement::ULID, 'userId' => '\d+'])]
    public function removeTeamMember(Request $request, Organization $organization, string $teamId, int $userId, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(OrganizationActions::RemoveTeamMember->value, $organization);
        $this->assertCsrf($request, 'org_team_member_remove_'.$teamId.'_'.$userId);

        try {
            $this->membershipManager->removeTeamMember($organization, $user, $this->teamId($teamId), $userId, $request->getClientIp());
            $this->addFlash('success', 'Member removed from the team.');
        } catch (OrganizationException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('organization_teams', ['organization' => $organization->slug]);
    }

    #[Route(path: '/organizations/{organization}/members', name: 'organization_members', methods: ['GET'], requirements: ['organization' => Slug::PATTERN])]
    public function members(Organization $organization): Response
    {
        // Any org member (or admin) may view the members list; management is owner-only per-action.
        $this->denyAccessUnlessGranted(OrganizationActions::View->value, $organization);

        return $this->render('organization/members.html.twig', [
            'organization' => $organization,
            'members' => $this->membersView($organization),
        ]);
    }

    #[Route(path: '/organizations/{organization}/members/{userId}/remove', name: 'organization_member_remove', methods: ['POST'], requirements: ['organization' => Slug::PATTERN, 'userId' => '\d+'])]
    public function removeMember(Request $request, Organization $organization, int $userId, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(OrganizationActions::RemoveMember->value, $organization);
        $this->assertCsrf($request, 'org_member_remove_'.$userId);

        try {
            $this->membershipManager->removeMember($organization, $user, $userId, $request->getClientIp());
            $this->addFlash('success', 'Member removed from the organization.');
        } catch (OrganizationException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('organization_members', ['organization' => $organization->slug]);
    }

    #[Route(path: '/organizations/{organization}/members/leave', name: 'organization_member_leave', methods: ['POST'], requirements: ['organization' => Slug::PATTERN])]
    public function leave(Request $request, Organization $organization, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(OrganizationActions::Leave->value, $organization);
        $this->assertCsrf($request, 'org_member_leave');

        try {
            $this->membershipManager->leave($organization, $user, $request->getClientIp());
            $this->addFlash('success', sprintf('You have left "%s".', $organization->displayName));

            return $this->redirectToRoute('organization_list');
        } catch (OrganizationException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('organization_members', ['organization' => $organization->slug]);
    }

    private function require2fa(User $user): ?Response
    {
        if ($user->isTotpAuthenticationEnabled()) {
            return null;
        }

        $this->addFlash('error', 'You must enable two-factor authentication to manage an organization.');

        return $this->redirectToRoute('user_2fa_configure', ['name' => $user->getUsername()]);
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }

    private function teamId(string $teamId): Ulid
    {
        if (!Ulid::isValid($teamId)) {
            throw new NotFoundHttpException('Invalid team id.');
        }

        return Ulid::fromString($teamId);
    }

    /**
     * @return list<array{team: OrganizationTeam, members: list<array{user: User|null, userId: int}>}>
     */
    private function teamsView(Organization $organization): array
    {
        $rows = $this->teamMembers->findByOrg($organization->id);
        $usersById = $this->usersById($rows);

        $membersByTeam = [];
        foreach ($rows as $row) {
            $membersByTeam[$row->teamId->toRfc4122()][] = [
                'user' => $usersById[$row->userId] ?? null,
                'userId' => $row->userId,
            ];
        }

        $view = [];
        foreach ($this->teams->findByOrg($organization->id) as $team) {
            $view[] = [
                'team' => $team,
                'members' => $membersByTeam[$team->teamId->toRfc4122()] ?? [],
            ];
        }

        return $view;
    }

    /**
     * @return list<array{user: User|null, userId: int, teams: non-empty-list<OrganizationTeam>}>
     */
    private function membersView(Organization $organization): array
    {
        $rows = $this->teamMembers->findByOrg($organization->id);
        $usersById = $this->usersById($rows);

        $teamsById = [];
        foreach ($this->teams->findByOrg($organization->id) as $team) {
            $teamsById[$team->teamId->toRfc4122()] = $team;
        }

        $teamsByUser = [];
        foreach ($rows as $row) {
            $team = $teamsById[$row->teamId->toRfc4122()] ?? null;
            if ($team !== null) {
                $teamsByUser[$row->userId][] = $team;
            }
        }

        $view = [];
        foreach ($teamsByUser as $userId => $teams) {
            $view[] = [
                'user' => $usersById[$userId] ?? null,
                'userId' => $userId,
                'teams' => $teams,
            ];
        }

        return $view;
    }

    /**
     * @param list<OrganizationTeamMember> $rows
     *
     * @return array<int, User>
     */
    private function usersById(array $rows): array
    {
        $userIds = array_values(array_unique(array_map(static fn (OrganizationTeamMember $row): int => $row->userId, $rows)));
        if ($userIds === []) {
            return [];
        }

        $usersById = [];
        foreach ($this->users->findBy(['id' => $userIds]) as $user) {
            $usersById[$user->getId()] = $user;
        }

        return $usersById;
    }
}
