<?php

namespace App\Tests\Fixtures;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\OrganizationStatus;
use App\Entity\OrganizationTeam;
use App\Entity\OrganizationTeamMember;
use App\Entity\Package;
use App\Entity\User;
use App\Organization\Domain\Organization as OrganizationAggregate;
use App\Organization\Domain\OrganizationTeamKind;
use Symfony\Component\Uid\Ulid;

trait Fixtures
{
    protected static function createOrganization(string $slug, string $displayName, ?\DateTimeImmutable $deletedAt = null): Organization
    {
        return new Organization(
            id: new Ulid(),
            slug: $slug,
            displayName: $displayName,
            status: $deletedAt !== null ? OrganizationStatus::Deleted : OrganizationStatus::Active,
            createdAt: new \DateTimeImmutable(),
            ownersTeamId: new Ulid(),
            allMembersTeamId: new Ulid(),
            deletedAt: $deletedAt,
            deletedReason: $deletedAt !== null ? 'owner' : null,
        );
    }

    /**
     * The two bootstrapped system teams (`owners` and `all organization members`), the owner's
     * membership in both, and the org-level membership record, mirroring what org creation projects.
     * Persist these alongside the organization so the owner is recognised as an owner and org member.
     *
     * @return array{OrganizationTeam, OrganizationTeamMember, OrganizationTeam, OrganizationTeamMember, OrganizationMember}
     */
    protected static function createOwnerMembership(Organization $organization, User $owner): array
    {
        $now = new \DateTimeImmutable();

        $ownersTeam = new OrganizationTeam(
            $organization->ownersTeamId,
            $organization,
            OrganizationTeamKind::System,
            OrganizationAggregate::OWNERS_TEAM_NAME,
            $owner,
            $now,
        );

        $orgMembership = new OrganizationMember($organization->id, $owner->getId(), $now);

        $ownerMembership = new OrganizationTeamMember(
            $organization->ownersTeamId,
            $owner->getId(),
            $organization->id,
            $owner,
            $now,
        );

        $allMembersTeam = new OrganizationTeam(
            $organization->allMembersTeamId,
            $organization,
            OrganizationTeamKind::System,
            OrganizationAggregate::ALL_ORGANIZATION_MEMBERS_TEAM_NAME,
            $owner,
            $now,
        );

        $allMembersMembership = new OrganizationTeamMember(
            $organization->allMembersTeamId,
            $owner->getId(),
            $organization->id,
            $owner,
            $now,
        );

        return [$ownersTeam, $ownerMembership, $allMembersTeam, $allMembersMembership, $orgMembership];
    }

    /**
     * Creates a Package entity without running the slow network-based repository initialization step
     *
     * @param array<User> $maintainers
     */
    protected static function createPackage(string $name, string $repository, ?string $remoteId = null, array $maintainers = []): Package
    {
        $package = new Package();

        $package->setName($name);
        $package->setRemoteId($remoteId);
        new \ReflectionProperty($package, 'repository')->setValue($package, $repository);
        if (\count($maintainers) > 0) {
            foreach ($maintainers as $user) {
                $package->addMaintainer($user);
                $user->addPackage($package);
            }
        }

        return $package;
    }

    /**
     * @param array<string> $roles
     */
    protected static function createUser(string $username = 'test', string $email = 'test@example.org', string $password = 'testtest', string $apiToken = 'api-token', string $safeApiToken = 'safe-api-token', string $githubId = '12345', bool $enabled = true, array $roles = []): User
    {
        $user = new User();
        $user->setEnabled($enabled);
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setPassword($password);
        $user->setApiToken($apiToken);
        $user->setSafeApiToken($safeApiToken);
        $user->setGithubId($githubId);
        $user->setRoles($roles);

        return $user;
    }
}
