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

namespace App\Tests\Organization;

use App\Entity\Organization as OrganizationReadModel;
use App\Entity\OrganizationInvitation;
use App\Entity\OrganizationInvitationRepository;
use App\Entity\OrganizationInvitationTeamRepository;
use App\Entity\OrganizationMemberRepository;
use App\Entity\OrganizationRepository;
use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\User;
use App\Organization\Domain\Exception\DuplicatePendingInvitationException;
use App\Organization\Domain\Exception\EmailMismatchException;
use App\Organization\Domain\Exception\PolicyNotMetException;
use App\Organization\Domain\Exception\TeamNotFoundException;
use App\Organization\Domain\InvitationStatus;
use App\Organization\InvitationManager;
use App\Organization\OrganizationManager;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;

class InvitationTest extends IntegrationTestCase
{
    public function testInviteCreatesPendingInvitationWithTeamAndToken(): void
    {
        $owner = $this->persistUser('owner', 'owner@example.org', twoFactor: true);
        $organization = $this->createOrg($owner, 'acme');

        $this->invitations()->invite($organization, $owner, 'alice@example.org', [$organization->ownersTeamId], null);

        $rows = static::getService(OrganizationInvitationRepository::class)->findByOrg($organization->id);
        self::assertCount(1, $rows);
        self::assertSame('alice@example.org', $rows[0]->email);
        self::assertSame(InvitationStatus::Pending, $rows[0]->status);
        self::assertSame(64, \strlen($rows[0]->tokenHash));
        self::assertSame($owner->getId(), $rows[0]->invitedBy?->getId());

        $teamIds = static::getService(OrganizationInvitationTeamRepository::class)->findTeamIds($rows[0]->id);
        self::assertCount(1, $teamIds);
        self::assertTrue($teamIds[0]->equals($organization->ownersTeamId));
    }

    public function testInviteRejectsUnknownTeam(): void
    {
        $owner = $this->persistUser('owner', 'owner@example.org', twoFactor: true);
        $organization = $this->createOrg($owner, 'acme');

        $this->expectException(TeamNotFoundException::class);
        $this->invitations()->invite($organization, $owner, 'alice@example.org', [new \Symfony\Component\Uid\Ulid()], null);
    }

    public function testInviteRejectsDuplicatePending(): void
    {
        $owner = $this->persistUser('owner', 'owner@example.org', twoFactor: true);
        $organization = $this->createOrg($owner, 'acme');

        $this->invitations()->invite($organization, $owner, 'alice@example.org', [$organization->ownersTeamId], null);

        $this->expectException(DuplicatePendingInvitationException::class);
        $this->invitations()->invite($organization, $owner, 'Alice@example.org', [$organization->ownersTeamId], null);
    }

    public function testAcceptAddsMembershipAndLogsJoinPublicly(): void
    {
        $connection = static::getService(Connection::class);
        $owner = $this->persistUser('owner', 'owner@example.org', twoFactor: true);
        $organization = $this->createOrg($owner, 'acme');
        $alice = $this->persistUser('alice', 'alice@example.org', twoFactor: true);

        $this->invitations()->invite($organization, $owner, 'alice@example.org', [$organization->ownersTeamId], null);
        $invitation = $this->pendingInvitation($organization);

        $this->invitations()->accept($invitation, $alice, null);

        $members = static::getService(OrganizationTeamMemberRepository::class);
        self::assertTrue($members->isOwner($organization->ownersTeamId, $alice->getId()));
        self::assertTrue($members->isMemberOfOrg($organization->id, $alice->getId()));
        // Every member is added to the all-members team too (owner + alice).
        self::assertSame(2, $members->countByTeam($organization->allMembersTeamId));

        // The org-level membership record is created alongside the team memberships.
        self::assertNotNull(static::getService(OrganizationMemberRepository::class)->findOneByOrgAndUser($organization->id, $alice->getId()));

        $reloaded = static::getService(OrganizationInvitationRepository::class)->find($invitation->id);
        self::assertNotNull($reloaded);
        self::assertSame(InvitationStatus::Accepted, $reloaded->status);
        self::assertNotNull($reloaded->resolvedAt);

        // The join is published once as an org-level organization_member_joined entry, plus one
        // organization_team_member_added entry per team she landed in (owners + all-members).
        self::assertSame(1, $this->auditCount($connection, $organization, 'organization_member_joined', 'alice'));
        self::assertSame(2, $this->auditCount($connection, $organization, 'organization_team_member_added', 'alice'));
    }

    public function testAcceptToOwnersRequiresTwoFactor(): void
    {
        $owner = $this->persistUser('owner', 'owner@example.org', twoFactor: true);
        $organization = $this->createOrg($owner, 'acme');
        $alice = $this->persistUser('alice', 'alice@example.org'); // no 2FA

        $this->invitations()->invite($organization, $owner, 'alice@example.org', [$organization->ownersTeamId], null);
        $invitation = $this->pendingInvitation($organization);

        $this->expectException(PolicyNotMetException::class);
        $this->invitations()->accept($invitation, $alice, null);
    }

    public function testAcceptRejectsEmailMismatch(): void
    {
        $owner = $this->persistUser('owner', 'owner@example.org', twoFactor: true);
        $organization = $this->createOrg($owner, 'acme');
        $mallory = $this->persistUser('mallory', 'mallory@example.org', twoFactor: true);

        $this->invitations()->invite($organization, $owner, 'alice@example.org', [$organization->ownersTeamId], null);
        $invitation = $this->pendingInvitation($organization);

        $this->expectException(EmailMismatchException::class);
        $this->invitations()->accept($invitation, $mallory, null);
    }

    public function testDeclineResolvesInvitationWithoutMembership(): void
    {
        $owner = $this->persistUser('owner', 'owner@example.org', twoFactor: true);
        $organization = $this->createOrg($owner, 'acme');
        $alice = $this->persistUser('alice', 'alice@example.org', twoFactor: true);

        $this->invitations()->invite($organization, $owner, 'alice@example.org', [$organization->ownersTeamId], null);
        $invitation = $this->pendingInvitation($organization);

        $this->invitations()->decline($invitation, $alice, null);

        $reloaded = static::getService(OrganizationInvitationRepository::class)->find($invitation->id);
        self::assertNotNull($reloaded);
        self::assertSame(InvitationStatus::Declined, $reloaded->status);
        self::assertFalse(static::getService(OrganizationTeamMemberRepository::class)->isMemberOfOrg($organization->id, $alice->getId()));
    }

    public function testRevokeResolvesInvitation(): void
    {
        $owner = $this->persistUser('owner', 'owner@example.org', twoFactor: true);
        $organization = $this->createOrg($owner, 'acme');

        $this->invitations()->invite($organization, $owner, 'alice@example.org', [$organization->ownersTeamId], null);
        $invitation = $this->pendingInvitation($organization);

        $this->invitations()->revoke($organization, $owner, $invitation, null);

        $reloaded = static::getService(OrganizationInvitationRepository::class)->find($invitation->id);
        self::assertNotNull($reloaded);
        self::assertSame(InvitationStatus::Revoked, $reloaded->status);
        self::assertNotNull($reloaded->resolvedAt);
    }

    public function testResendKeepsInvitationPendingAndRotatesToken(): void
    {
        $owner = $this->persistUser('owner', 'owner@example.org', twoFactor: true);
        $organization = $this->createOrg($owner, 'acme');

        $this->invitations()->invite($organization, $owner, 'alice@example.org', [$organization->ownersTeamId], null);
        $invitation = $this->pendingInvitation($organization);
        $originalHash = $invitation->tokenHash;
        $originalExpiry = $invitation->expiresAt;

        $this->invitations()->resend($organization, $owner, $invitation, null);

        $reloaded = static::getService(OrganizationInvitationRepository::class)->find($invitation->id);
        self::assertNotNull($reloaded);
        self::assertSame(InvitationStatus::Pending, $reloaded->status);
        self::assertNotSame($originalHash, $reloaded->tokenHash);
        self::assertGreaterThanOrEqual($originalExpiry, $reloaded->expiresAt);
    }

    private function auditCount(Connection $connection, OrganizationReadModel $organization, string $type, string $memberUsername): int
    {
        return (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE type = :type AND organizationId = :org
             AND JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.member.username')) = :member",
            ['type' => $type, 'org' => $organization->id->toBinary(), 'member' => $memberUsername],
        );
    }

    private function pendingInvitation(OrganizationReadModel $organization): OrganizationInvitation
    {
        $rows = static::getService(OrganizationInvitationRepository::class)->findByOrg($organization->id);
        self::assertNotEmpty($rows);

        return $rows[0];
    }

    private function createOrg(User $owner, string $slug): OrganizationReadModel
    {
        static::getService(OrganizationManager::class)->create($owner, $owner, $slug, 'ACME Corp', null);
        $organization = static::getService(OrganizationRepository::class)->findOneBySlug($slug);
        self::assertNotNull($organization);

        return $organization;
    }

    private function invitations(): InvitationManager
    {
        return static::getService(InvitationManager::class);
    }

    private function persistUser(string $username, string $email, bool $twoFactor = false): User
    {
        $user = self::createUser($username, $email);
        if ($twoFactor) {
            $user->setTotpSecret('totp-secret');
        }
        $this->store($user);

        return $user;
    }
}
