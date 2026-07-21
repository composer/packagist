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

namespace App\Security\Voter;

use App\Entity\Organization;
use App\Entity\OrganizationMemberRepository;
use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<value-of<OrganizationActions>, Organization>
 */
class OrganizationVoter extends Voter
{
    public function __construct(
        private Security $security,
        private OrganizationTeamMemberRepository $organizationTeamMemberRepo,
        private OrganizationMemberRepository $organizationMemberRepo,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Organization && OrganizationActions::tryFrom($attribute) instanceof OrganizationActions;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var Organization $organization */
        $organization = $subject;

        $action = OrganizationActions::from($attribute);

        // A packagist-admin may perform any owner action on any org for moderation, and is
        // the only actor who can restore. Their authority derives from admin status.
        if ($this->security->isGranted('ROLE_ADMIN_ORGS')) {
            return true;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($organization->isDeleted()) {
            return false;
        }

        $reason = $this->denialReason($action, $organization, $user);
        if ($reason !== null) {
            return $this->deny($vote, $reason);
        }

        return true;
    }

    /**
     * The reason the action is denied, or null when it is allowed. Each owner action changes the
     * org, so management requires an active owner with 2FA; the reasons are ordered so the most
     * fundamental obstacle wins, and {@see OrganizationAccessDeniedReason::TwoFactorRequired} is
     * only reported for an owner of a live org, matching what the access-denied listener acts on.
     */
    private function denialReason(OrganizationActions $action, Organization $organization, User $user): ?OrganizationAccessDeniedReason
    {
        return match ($action) {
            // Owners have no visibility into a hidden org, so restore is packagist-admin only.
            OrganizationActions::Restore => OrganizationAccessDeniedReason::AdminOnly,
            OrganizationActions::View,
            OrganizationActions::ViewMembers,
            OrganizationActions::ViewTeams,
            OrganizationActions::Leave => $this->memberDenialReason($organization, $user),
            OrganizationActions::Edit,
            OrganizationActions::ViewAuditLog,
            OrganizationActions::SoftDelete,
            OrganizationActions::CreateTeam,
            OrganizationActions::RenameTeam,
            OrganizationActions::DeleteTeam,
            OrganizationActions::AddTeamMember,
            OrganizationActions::RemoveTeamMember,
            OrganizationActions::RemoveMember,
            OrganizationActions::ViewInvitations,
            OrganizationActions::InviteMember,
            OrganizationActions::ResendInvitation,
            OrganizationActions::RevokeInvitation => $this->manageDenialReason($organization, $user),
        };
    }

    private function memberDenialReason(Organization $organization, User $user): ?OrganizationAccessDeniedReason
    {
        if (!$this->isMember($organization, $user)) {
            return OrganizationAccessDeniedReason::NotAMember;
        }

        return null;
    }

    private function manageDenialReason(Organization $organization, User $user): ?OrganizationAccessDeniedReason
    {
        return match (true) {
            !$this->isOwner($organization, $user) => OrganizationAccessDeniedReason::NotAnOwner,
            !$user->isTotpAuthenticationEnabled() => OrganizationAccessDeniedReason::TwoFactorRequired,
            default => null,
        };
    }

    private function deny(?Vote $vote, OrganizationAccessDeniedReason $reason): bool
    {
        if ($vote !== null) {
            $vote->addReason($reason->message());
            $vote->extraData[OrganizationAccessDeniedReason::VOTE_KEY] = $reason;
        }

        return false;
    }

    private function isOwner(Organization $organization, User $user): bool
    {
        return $this->organizationTeamMemberRepo->isOwner($organization->ownersTeamId, $user->getId());
    }

    private function isMember(Organization $organization, User $user): bool
    {
        return $this->organizationMemberRepo->findOneByOrgAndUser($organization->id, $user->getId()) !== null;
    }
}
