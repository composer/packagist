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
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($action) {
            // Owners have no visibility into a hidden org, so restore is packagist-admin only.
            OrganizationActions::Restore => false,
            OrganizationActions::View,
            OrganizationActions::EditDisplayInfo,
            OrganizationActions::EditSlug,
            OrganizationActions::SoftDelete => $this->isOwner($organization, $user) && !$organization->isDeleted(),
        };
    }

    private function isOwner(Organization $organization, User $user): bool
    {
        // Until the membership management is done, the owner is the creating user.
        return $organization->createdBy === $user->getId();
    }

    private function isMember(Organization $organization, User $user): bool
    {
        return $this->isOwner($organization, $user);
    }
}
