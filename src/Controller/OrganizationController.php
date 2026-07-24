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

use App\Audit\AuditRecordType;
use App\Audit\Display\AuditLogDisplayFactory;
use App\Entity\AuditRecordRepository;
use App\Entity\Organization;
use App\Entity\OrganizationInvitation;
use App\Entity\OrganizationInvitationRepository;
use App\Entity\OrganizationInvitationTeamRepository;
use App\Entity\OrganizationMemberRepository;
use App\Entity\OrganizationRepository;
use App\Entity\OrganizationTeam;
use App\Entity\OrganizationTeamMember;
use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\OrganizationTeamRepository;
use App\Entity\User;
use App\Entity\UserRepository;
use App\Form\Model\AddTeamMemberRequest;
use App\Form\Model\InviteMemberRequest;
use App\Form\Model\OrganizationDetailsRequest;
use App\Form\Model\TeamRequest;
use App\Form\Type\AddTeamMemberType;
use App\Form\Type\DeleteTeamType;
use App\Form\Type\InviteMemberType;
use App\Form\Type\LeaveOrganizationType;
use App\Form\Type\OrganizationDetailsType;
use App\Form\Type\RemoveMemberType;
use App\Form\Type\RemoveTeamMemberType;
use App\Form\Type\ResendInvitationType;
use App\Form\Type\RevokeInvitationType;
use App\Form\Type\TeamType;
use App\Organization\Domain\Exception\OrganizationException;
use App\Organization\Domain\Organization as OrganizationDomain;
use App\Organization\Domain\Slug;
use App\Organization\InvitationManager;
use App\Organization\OrganizationManager;
use App\Organization\OrganizationMembershipManager;
use App\QueryFilter\AuditLog\ActorFilter;
use App\QueryFilter\AuditLog\AuditRecordTypeFilter;
use App\QueryFilter\AuditLog\DateTimeFromFilter;
use App\QueryFilter\AuditLog\DateTimeToFilter;
use App\QueryFilter\QueryFilterInterface;
use App\Security\Voter\OrganizationActions;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Psr\Clock\ClockInterface;
use Pagerfanta\Pagerfanta;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
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
    /** How many days a resolved (accepted/declined/revoked/expired) invitation stays in the list. */
    private const int RESOLVED_INVITATION_VISIBILITY_DAYS = 7;

    public function __construct(
        private readonly OrganizationManager $organizationManager,
        private readonly OrganizationMembershipManager $membershipManager,
        private readonly InvitationManager $invitationManager,
        private readonly OrganizationRepository $organizationRepo,
        private readonly OrganizationTeamRepository $organizationTeamRepo,
        private readonly OrganizationTeamMemberRepository $organizationTeamMemberRepo,
        private readonly OrganizationInvitationRepository $organizationInvitationRepo,
        private readonly OrganizationInvitationTeamRepository $organizationInvitationTeamRepo,
        private readonly OrganizationMemberRepository $organizationMemberRepo,
        private readonly UserRepository $userRepo,
        private readonly ClockInterface $clock,
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

    #[IsGranted(OrganizationActions::ViewAuditLog->value, 'organization')]
    #[Route(path: '/organizations/{organization}/audit-log', name: 'organization_audit_log', methods: ['GET'], requirements: ['organization' => Slug::PATTERN])]
    public function auditLog(Request $request, Organization $organization, AuditRecordRepository $auditRecordRepository, AuditLogDisplayFactory $displayFactory): Response
    {
        $isAuditAdmin = $this->isGranted('ROLE_AUDITOR');

        $dateTimeFromFilter = DateTimeFromFilter::fromQuery($request->query);
        $dateTimeToFilter = DateTimeToFilter::fromQuery($request->query);

        /** @var QueryFilterInterface[] $filters */
        $filters = [
            AuditRecordTypeFilter::fromQuery($request->query),
            ActorFilter::fromQuery($request->query, 'actor', $isAuditAdmin),
            $dateTimeFromFilter,
            $dateTimeToFilter,
        ];

        $qb = $auditRecordRepository->createQueryBuilder('a')
            ->where('a.organizationId = :organizationId')
            ->setParameter('organizationId', $organization->id, UlidType::NAME)
            ->orderBy('a.id', 'DESC');

        foreach ($filters as $filter) {
            $filter->filter($qb);
        }

        $auditLogs = new Pagerfanta(new QueryAdapter($qb, false, false));
        $auditLogs->setNormalizeOutOfRangePages(true);
        $auditLogs->setMaxPerPage(20);
        $auditLogs->setCurrentPage(max(1, $request->query->getInt('page', 1)));

        $selectedFilters = [];
        foreach ($filters as $filter) {
            $selectedFilters[$filter->getKey()] = $filter->getSelectedValue();
        }

        return $this->render('organization/audit_log.html.twig', [
            'organization' => $organization,
            'auditLogDisplays' => $displayFactory->build($auditLogs, revealEmails: true),
            'auditLogPaginator' => $auditLogs,
            'types' => AuditRecordType::organizationCases(),
            'selectedFilters' => $selectedFilters,
            'dateTimeFromFilter' => $dateTimeFromFilter,
            'dateTimeToFilter' => $dateTimeToFilter,
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
            $target = $this->organizationMemberRepo->findOrgMember($organization->id, $addRequest->username);
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

    #[IsGranted(OrganizationActions::ViewInvitations->value, 'organization')]
    #[Route(path: '/organizations/{organization}/invitations', name: 'organization_invitations', methods: ['GET'], requirements: ['organization' => Slug::PATTERN])]
    public function invitations(Organization $organization): Response
    {
        $teamNamesById = [];
        foreach ($this->organizationTeamRepo->findByOrg($organization->id) as $team) {
            $teamNamesById[$team->teamId->toRfc4122()] = $team->name;
        }

        $resolvedCutoff = $this->clock->now()->sub(new \DateInterval('P'.self::RESOLVED_INVITATION_VISIBILITY_DAYS.'D'));
        $invitationRows = $this->organizationInvitationRepo->findVisibleByOrg($organization->id, $resolvedCutoff);
        $teamIdsByInvitation = $this->organizationInvitationTeamRepo->findTeamIdsByInvitation(
            array_map(static fn (OrganizationInvitation $invitation): Ulid => $invitation->id, $invitationRows),
        );

        $invitations = [];
        foreach ($invitationRows as $invitation) {
            $teamNames = [];
            foreach ($teamIdsByInvitation[$invitation->id->toRfc4122()] ?? [] as $teamId) {
                $teamNames[] = $teamNamesById[$teamId->toRfc4122()] ?? '(deleted team)';
            }

            $invitations[] = ['invitation' => $invitation, 'teamNames' => $teamNames];
        }

        return $this->render('organization/invitations.html.twig', [
            'organization' => $organization,
            'invitations' => $invitations,
            'now' => $this->clock->now(),
        ]);
    }

    #[IsGranted(OrganizationActions::InviteMember->value, 'organization')]
    #[Route(path: '/organizations/{organization}/invitations/invite', name: 'organization_invitation_create', methods: ['GET', 'POST'], requirements: ['organization' => Slug::PATTERN])]
    public function inviteMember(Request $request, Organization $organization, #[CurrentUser] User $user): Response
    {
        $inviteRequest = new InviteMemberRequest();
        $form = $this->createForm(InviteMemberType::class, $inviteRequest, ['teams' => $this->invitableTeamChoices($organization)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $teamIds = array_map(static fn (string $id): Ulid => Ulid::fromString($id), $inviteRequest->teamIds);
                $this->invitationManager->invite($organization, $user, $inviteRequest->email, $teamIds, $request->getClientIp());
                $this->addFlash('success', sprintf('Invitation sent to "%s".', $inviteRequest->email));

                return $this->redirectToRoute('organization_invitations', ['organization' => $organization->slug]);
            } catch (OrganizationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('organization/invitation_create.html.twig', [
            'organization' => $organization,
            'allMembersTeamName' => OrganizationDomain::ALL_ORGANIZATION_MEMBERS_TEAM_NAME,
            'form' => $form->createView(),
        ]);
    }

    #[IsGranted(OrganizationActions::ResendInvitation->value, 'organization')]
    #[Route(path: '/organizations/{organization}/invitations/{invitation}/resend', name: 'organization_invitation_resend', methods: ['GET', 'POST'], requirements: ['organization' => Slug::PATTERN, 'invitation' => Requirement::ULID])]
    public function resendInvitation(Request $request, Organization $organization, OrganizationInvitation $invitation, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(ResendInvitationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->invitationManager->resend($organization, $user, $invitation, $request->getClientIp());
                $this->addFlash('success', sprintf('Invitation to "%s" re-sent.', $invitation->email));

                return $this->redirectToRoute('organization_invitations', ['organization' => $organization->slug]);
            } catch (OrganizationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('organization/invitation_resend.html.twig', [
            'organization' => $organization,
            'invitation' => $invitation,
            'expiryDays' => InvitationManager::INVITATION_EXPIRY_DAYS,
            'form' => $form->createView(),
        ]);
    }

    #[IsGranted(OrganizationActions::RevokeInvitation->value, 'organization')]
    #[Route(path: '/organizations/{organization}/invitations/{invitation}/revoke', name: 'organization_invitation_revoke', methods: ['GET', 'POST'], requirements: ['organization' => Slug::PATTERN, 'invitation' => Requirement::ULID])]
    public function revokeInvitation(Request $request, Organization $organization, OrganizationInvitation $invitation, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(RevokeInvitationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->invitationManager->revoke($organization, $user, $invitation, $request->getClientIp());
                $this->addFlash('success', sprintf('Invitation to "%s" revoked.', $invitation->email));

                return $this->redirectToRoute('organization_invitations', ['organization' => $organization->slug]);
            } catch (OrganizationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('organization/invitation_revoke.html.twig', [
            'organization' => $organization,
            'invitation' => $invitation,
            'form' => $form->createView(),
        ]);
    }

    /**
     * The teams an owner may invite to: every team except the automatically-managed all-members team.
     *
     * @return array<string, string> team name => team id (rfc4122)
     */
    private function invitableTeamChoices(Organization $organization): array
    {
        $choices = [];
        foreach ($this->organizationTeamRepo->findByOrg($organization->id) as $team) {
            if ($team->teamId->equals($organization->allMembersTeamId)) {
                continue;
            }

            $choices[$team->name] = $team->teamId->toRfc4122();
        }

        return $choices;
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
        foreach ($this->userRepo->findBy(['id' => $userIds]) as $user) {
            $usersById[$user->getId()] = $user;
        }

        return $usersById;
    }
}
