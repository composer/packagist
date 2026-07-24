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
use App\Form\Type\DeleteTeamType;
use App\Form\Type\LeaveOrganizationType;
use App\Form\Type\OrganizationDetailsType;
use App\Form\Type\RemoveMemberType;
use App\Form\Type\RemoveTeamMemberType;
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

class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationManager $organizationManager,
        private readonly OrganizationMembershipManager $membershipManager,
        private readonly OrganizationRepository $organizationRepo,
        private readonly OrganizationTeamRepository $organizationTeamRepo,
        private readonly OrganizationTeamMemberRepository $organizationTeamMemberRepo,
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

    #[IsGranted(OrganizationActions::View->value, 'organization')]
    #[Route(path: '/organizations/{organization}', name: 'organization_show', methods: ['GET'], requirements: ['organization' => Slug::PATTERN])]
    public function show(Organization $organization): Response
    {
        return $this->render('organization/show.html.twig', [
            'organization' => $organization,
        ]);
    }

    #[IsGranted(OrganizationActions::Edit->value, 'organization')]
    #[Route(path: '/organizations/{organization}/settings', name: 'organization_settings', methods: ['GET', 'POST'], requirements: ['organization' => Slug::PATTERN])]
    public function settings(Request $request, Organization $organization, #[CurrentUser] User $user): Response
    {
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

    #[IsGranted(OrganizationActions::ViewTeams->value, 'organization')]
    #[Route(path: '/organizations/{organization}/teams', name: 'organization_teams', methods: ['GET'], requirements: ['organization' => Slug::PATTERN])]
    public function teams(Organization $organization): Response
    {
        $rows = $this->organizationTeamMemberRepo->findByOrg($organization->id);
        $usersById = $this->usersById($rows);

        $membersByTeam = [];
        foreach ($rows as $row) {
            $membersByTeam[$row->teamId->toRfc4122()][] = [
                'user' => $usersById[$row->userId] ?? null,
                'userId' => $row->userId,
            ];
        }

        $teams = [];
        foreach ($this->organizationTeamRepo->findByOrg($organization->id) as $team) {
            $teams[] = [
                'team' => $team,
                'members' => $membersByTeam[$team->teamId->toRfc4122()] ?? [],
            ];
        }

        // Show the two system teams first in a fixed order (Owners, then All organization members),
        // then custom teams in findByOrg's name order (usort is stable on PHP 8+).
        $rank = static function (OrganizationTeam $team) use ($organization): int {
            return match (true) {
                $team->teamId->equals($organization->ownersTeamId) => 0,
                $team->teamId->equals($organization->allMembersTeamId) => 1,
                default => 2,
            };
        };
        usort($teams, static fn (array $a, array $b): int => $rank($a['team']) <=> $rank($b['team']));

        return $this->render('organization/teams.html.twig', [
            'organization' => $organization,
            'teams' => $teams,
        ]);
    }

    #[IsGranted(OrganizationActions::CreateTeam->value, 'organization')]
    #[Route(path: '/organizations/{organization}/teams/create', name: 'organization_team_create', methods: ['GET', 'POST'], requirements: ['organization' => Slug::PATTERN])]
    public function createTeam(Request $request, Organization $organization, #[CurrentUser] User $user): Response
    {
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

    #[IsGranted(OrganizationActions::RenameTeam->value, 'organization')]
    #[Route(path: '/organizations/{organization}/teams/{team}/rename', name: 'organization_team_rename', methods: ['GET', 'POST'], requirements: ['organization' => Slug::PATTERN, 'team' => Requirement::ULID])]
    public function renameTeam(Request $request, Organization $organization, OrganizationTeam $team, #[CurrentUser] User $user): Response
    {
        if ($team->isSystem()) {
            throw new NotFoundHttpException('Team not found.');
        }

        $teamRequest = new TeamRequest();
        $teamRequest->name = $team->name;

        $form = $this->createForm(TeamType::class, $teamRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $previousName = $team->name;
                $this->membershipManager->renameTeam($organization, $user, $team->teamId, $teamRequest->name, $request->getClientIp());
                $this->addFlash('success', sprintf('Team "%s" renamed to "%s".', $previousName, $teamRequest->name));

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

    #[IsGranted(OrganizationActions::DeleteTeam->value, 'organization')]
    #[Route(path: '/organizations/{organization}/teams/{team}/delete', name: 'organization_team_delete', methods: ['GET', 'POST'], requirements: ['organization' => Slug::PATTERN, 'team' => Requirement::ULID])]
    public function deleteTeam(Request $request, Organization $organization, OrganizationTeam $team, #[CurrentUser] User $user): Response
    {
        if ($team->isSystem()) {
            throw new NotFoundHttpException('Team not found.');
        }

        $form = $this->createForm(DeleteTeamType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $teamName = $team->name;
                $this->membershipManager->deleteTeam($organization, $user, $team->teamId, $request->getClientIp());
                $this->addFlash('success', sprintf('Team "%s" deleted.', $teamName));

                return $this->redirectToRoute('organization_teams', ['organization' => $organization->slug]);
            } catch (OrganizationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('organization/team_delete.html.twig', [
            'organization' => $organization,
            'team' => $team,
            'form' => $form->createView(),
        ]);
    }

    #[IsGranted(OrganizationActions::AddTeamMember->value, 'organization')]
    #[Route(path: '/organizations/{organization}/teams/{team}/members/add', name: 'organization_team_member_add', methods: ['GET', 'POST'], requirements: ['organization' => Slug::PATTERN, 'team' => Requirement::ULID])]
    public function addTeamMember(Request $request, Organization $organization, OrganizationTeam $team, #[CurrentUser] User $user): Response
    {
        // The all-members team's roster is managed automatically; it has no manual add flow.
        if ($team->teamId->equals($organization->allMembersTeamId)) {
            throw new NotFoundHttpException('Team not found.');
        }

        $addRequest = new AddTeamMemberRequest();
        $form = $this->createForm(AddTeamMemberType::class, $addRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $target = $this->organizationTeamMemberRepo->findOrgMember($organization->slug, $addRequest->username);
            if ($target === null) {
                $form->addError(new FormError(sprintf('No member "%s" was found in this organization.', $addRequest->username)));
            } else {
                try {
                    $this->membershipManager->addTeamMember($organization, $user, $team->teamId, $target->getId(), $request->getClientIp());
                    $this->addFlash('success', sprintf('Added "%s" to team "%s".', $target->getUsername(), $team->name));

                    return $this->redirectToRoute('organization_teams', ['organization' => $organization->slug]);
                } catch (OrganizationException $e) {
                    $form->addError(new FormError($e->getMessage()));
                }
            }
        }

        return $this->render('organization/team_member_add.html.twig', [
            'organization' => $organization,
            'team' => $team,
            'form' => $form->createView(),
        ]);
    }

    #[IsGranted(OrganizationActions::RemoveTeamMember->value, 'organization')]
    #[Route(path: '/organizations/{organization}/teams/{team}/members/{teamMember}/remove', name: 'organization_team_member_remove', methods: ['GET', 'POST'], requirements: ['organization' => Slug::PATTERN, 'team' => Requirement::ULID])]
    public function removeTeamMember(Request $request, Organization $organization, OrganizationTeam $team, User $teamMember, #[CurrentUser] User $user): Response
    {
        // The all-members team's roster is managed automatically; it has no manual remove flow.
        if ($team->teamId->equals($organization->allMembersTeamId)) {
            throw new NotFoundHttpException('Team not found.');
        }

        // The last owner cannot be removed: the org must always keep someone who can manage it.
        // Explain this up front and offer no removal form, only a way back.
        if ($team->teamId->equals($organization->ownersTeamId) && $this->organizationTeamMemberRepo->countByTeam($organization->ownersTeamId) === 1) {
            return $this->render('organization/team_member_remove.html.twig', [
                'organization' => $organization,
                'team' => $team,
                'member' => $teamMember,
                'form' => null,
                'isLastOwner' => true,
            ]);
        }

        $form = $this->createForm(RemoveTeamMemberType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->membershipManager->removeTeamMember($organization, $user, $team->teamId, $teamMember->getId(), $request->getClientIp());
                $this->addFlash('success', sprintf('Removed "%s" from team "%s".', $teamMember->getUsername(), $team->name));

                return $this->redirectToRoute('organization_teams', ['organization' => $organization->slug]);
            } catch (OrganizationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('organization/team_member_remove.html.twig', [
            'organization' => $organization,
            'team' => $team,
            'member' => $teamMember,
            'form' => $form->createView(),
            'isLastOwner' => false,
        ]);
    }

    #[IsGranted(OrganizationActions::ViewMembers->value, 'organization')]
    #[Route(path: '/organizations/{organization}/members', name: 'organization_members', methods: ['GET'], requirements: ['organization' => Slug::PATTERN])]
    public function members(Organization $organization): Response
    {
        $rows = $this->organizationTeamMemberRepo->findByOrg($organization->id);
        $usersById = $this->usersById($rows);

        $teamsById = [];
        foreach ($this->organizationTeamRepo->findByOrg($organization->id) as $team) {
            $teamsById[$team->teamId->toRfc4122()] = $team;
        }

        $teamsByUser = [];
        foreach ($rows as $row) {
            $team = $teamsById[$row->teamId->toRfc4122()] ?? null;
            if ($team !== null) {
                $teamsByUser[$row->userId][] = $team;
            }
        }

        $members = [];
        foreach ($teamsByUser as $userId => $teams) {
            $members[] = [
                'user' => $usersById[$userId] ?? null,
                'userId' => $userId,
                'teams' => $teams,
            ];
        }

        return $this->render('organization/members.html.twig', [
            'organization' => $organization,
            'members' => $members,
        ]);
    }

    #[IsGranted(OrganizationActions::RemoveMember->value, 'organization')]
    #[Route(path: '/organizations/{organization}/members/{organizationMember}/remove', name: 'organization_member_remove', methods: ['GET', 'POST'], requirements: ['organization' => Slug::PATTERN])]
    public function removeMember(Request $request, Organization $organization, User $organizationMember, #[CurrentUser] User $user): Response
    {
        // The last owner cannot be removed: the org must always keep someone who can manage it.
        // Explain this up front and offer no removal form, only a way back.
        if ($this->organizationTeamMemberRepo->isOwner($organization->ownersTeamId, $organizationMember->getId()) && $this->organizationTeamMemberRepo->countByTeam($organization->ownersTeamId) === 1) {
            return $this->render('organization/member_remove.html.twig', [
                'organization' => $organization,
                'member' => $organizationMember,
                'form' => null,
                'isLastOwner' => true,
            ]);
        }

        $form = $this->createForm(RemoveMemberType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->membershipManager->removeMember($organization, $user, $organizationMember->getId(), $request->getClientIp());
                $this->addFlash('success', sprintf('Removed "%s" from the organization.', $organizationMember->getUsername()));

                return $this->redirectToRoute('organization_members', ['organization' => $organization->slug]);
            } catch (OrganizationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('organization/member_remove.html.twig', [
            'organization' => $organization,
            'member' => $organizationMember,
            'form' => $form->createView(),
            'isLastOwner' => false,
        ]);
    }

    #[IsGranted(OrganizationActions::Leave->value, 'organization')]
    #[Route(path: '/organizations/{organization}/members/leave', name: 'organization_member_leave', methods: ['GET', 'POST'], requirements: ['organization' => Slug::PATTERN])]
    public function leave(Request $request, Organization $organization, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(LeaveOrganizationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->membershipManager->leave($organization, $user, $request->getClientIp());
                $this->addFlash('success', sprintf('You have left "%s".', $organization->displayName));

                return $this->redirectToRoute('organization_list');
            } catch (OrganizationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('organization/member_leave.html.twig', [
            'organization' => $organization,
            'form' => $form->createView(),
        ]);
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
