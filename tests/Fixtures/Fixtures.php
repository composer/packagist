<?php

namespace App\Tests\Fixtures;

use App\Entity\Organization;
use App\Entity\OrganizationStatus;
use App\Entity\OrganizationTeam;
use App\Entity\OrganizationTeamKind;
use App\Entity\OrganizationTeamMember;
use App\Entity\Package;
use App\Entity\User;
use App\Organization\Domain\Organization as OrganizationAggregate;
use Symfony\Component\Uid\Ulid;

trait Fixtures
{
    protected static function createOrganization(string $slug, string $displayName, ?User $owner = null, ?\DateTimeImmutable $deletedAt = null): Organization
    {
        return new Organization(
            id: new Ulid(),
            slug: $slug,
            displayName: $displayName,
            status: $deletedAt !== null ? OrganizationStatus::Deleted : OrganizationStatus::Active,
            createdAt: new \DateTimeImmutable(),
            createdBy: $owner,
            ownersTeamId: new Ulid(),
            deletedAt: $deletedAt,
            deletedReason: $deletedAt !== null ? 'owner' : null,
        );
    }

    /**
     * The bootstrapped `owners` team and the owner's membership, mirroring what OrganizationCreated
     * projects. Persist these alongside the organization so the owner is recognised as an owner.
     *
     * @return array{OrganizationTeam, OrganizationTeamMember}
     */
    protected static function createOwnerMembership(Organization $organization, User $owner): array
    {
        \Webmozart\Assert\Assert::notNull($organization->ownersTeamId);

        $team = new OrganizationTeam(
            $organization->ownersTeamId,
            $organization->id,
            OrganizationTeamKind::System,
            OrganizationAggregate::OWNERS_TEAM_NAME,
            $owner,
            new \DateTimeImmutable(),
        );

        $member = new OrganizationTeamMember(
            $organization->ownersTeamId,
            $owner->getId(),
            $organization->id,
            $owner,
            new \DateTimeImmutable(),
        );

        return [$team, $member];
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
