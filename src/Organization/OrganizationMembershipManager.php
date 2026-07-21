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

namespace App\Organization;

use App\Entity\Organization as OrganizationReadModel;
use App\Entity\User;
use App\Entity\UserRepository;
use App\Organization\Domain\Exception\TeamNameTakenException;
use App\Organization\Domain\Organization;
use App\Organization\Domain\TeamName;
use App\Organization\EventStore\Actor;
use App\Organization\EventStore\EventStore;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Ulid;

/**
 * Application service for team & member management. Follows the reconstitute → command → append
 * pattern of {@see OrganizationManager}: external facts (2FA, admin status) are resolved here, the
 * aggregate enforces the domain invariants, and the projection's unique constraint is the final
 * backstop for team-name uniqueness.
 */
final class OrganizationMembershipManager
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly UserRepository $userRepo,
        private readonly Security $security,
    ) {
    }

    /**
     * @throws TeamNameTakenException
     */
    public function createTeam(OrganizationReadModel $organization, User $actor, string $name, ?string $ip): void
    {
        $teamName = new TeamName($name);

        $this->mutate($organization, $actor, static fn (Organization $org) => $org->createTeam(new Ulid(), $teamName), $ip);
    }

    /**
     * @throws \App\Organization\Domain\Exception\TeamNotFoundException
     * @throws \App\Organization\Domain\Exception\TeamProtectedException
     * @throws TeamNameTakenException
     */
    public function renameTeam(OrganizationReadModel $organization, User $actor, Ulid $teamId, string $name, ?string $ip): void
    {
        $teamName = new TeamName($name);

        $this->mutate($organization, $actor, static fn (Organization $org) => $org->renameTeam($teamId, $teamName), $ip);
    }

    public function deleteTeam(OrganizationReadModel $organization, User $actor, Ulid $teamId, ?string $ip): void
    {
        $this->mutate($organization, $actor, static fn (Organization $org) => $org->deleteTeam($teamId), $ip);
    }

    public function addTeamMember(OrganizationReadModel $organization, User $actor, Ulid $teamId, int $userId, ?string $ip): void
    {
        $targetHasTwoFactor = $this->userRepo->find($userId)?->isTotpAuthenticationEnabled() ?? false;

        $this->mutate($organization, $actor, static fn (Organization $org) => $org->addTeamMember($teamId, $userId, $targetHasTwoFactor), $ip);
    }

    public function removeTeamMember(OrganizationReadModel $organization, User $actor, Ulid $teamId, int $userId, ?string $ip): void
    {
        $this->mutate($organization, $actor, static fn (Organization $org) => $org->removeTeamMember($teamId, $userId), $ip);
    }

    public function removeMember(OrganizationReadModel $organization, User $actor, int $userId, ?string $ip): void
    {
        $this->mutate($organization, $actor, static fn (Organization $org) => $org->removeMember($userId), $ip);
    }

    /**
     * The acting user leaves the org entirely (all teams).
     */
    public function leave(OrganizationReadModel $organization, User $actor, ?string $ip): void
    {
        $this->mutate($organization, $actor, static fn (Organization $org) => $org->leave($actor->getId()), $ip);
    }

    /**
     * @param callable(Organization): void $command
     */
    private function mutate(OrganizationReadModel $organization, User $actor, callable $command, ?string $ip): void
    {
        $aggregate = Organization::reconstitute(
            $organization->id,
            $this->eventStore->loadHistory($organization->id),
        );

        $command($aggregate);

        try {
            $this->eventStore->append($aggregate, $this->actorFor($aggregate, $actor), $ip);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'org_team_name_uniq')) {
                throw new TeamNameTakenException('A team with this name already exists in this organization.', 0, $e);
            }

            throw $e;
        }
    }

    /**
     * An owner acts as `owner`; a platform moderator who is not an owner acts as `packagist-admin`;
     * any other org member (e.g. leaving on their own) acts as a plain `member`.
     */
    private function actorFor(Organization $aggregate, User $actor): Actor
    {
        if ($aggregate->isOwner($actor->getId())) {
            return Actor::member($actor);
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return Actor::packagistAdmin($actor);
        }

        return Actor::member($actor);
    }
}
