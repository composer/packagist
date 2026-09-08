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

namespace App\Tests\Controller;

use App\Audit\AuditRecordType;
use App\Entity\AuditRecord;
use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\OrganizationRepository;
use App\Entity\OrganizationTeam;
use App\Entity\OrganizationTeamMember;
use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\OrganizationTeamRepository;
use App\Entity\User;
use App\Organization\Domain\OrganizationTeamKind;
use App\Organization\OrganizationManager;
use App\Organization\OrganizationMembershipManager;
use App\Tests\IntegrationTestCase;
use Symfony\Component\Uid\Ulid;

class OrganizationControllerTest extends IntegrationTestCase
{
    public function testShowRendersActiveOrganization(): void
    {
        $user = self::createUser();
        $this->store($user);
        $this->persistOrganization('acme', 'ACME Corp', owner: $user);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/organizations/acme');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ACME Corp', $crawler->filter('.title')->text());
    }

    public function testShowReturns404ForUnknownSlug(): void
    {
        $this->client->request('GET', '/organizations/does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowReturns410ForDeletedOrganization(): void
    {
        $this->persistOrganization('acme', 'ACME Corp', deletedAt: new \DateTimeImmutable());

        $this->client->request('GET', '/organizations/acme');

        // A soft-deleted org is invisible to everyone except Packagist admins.
        self::assertResponseStatusCodeSame(410);
    }

    public function testAdminCanViewDeletedOrganization(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN_ORGS']);
        $this->store($admin);
        $this->persistOrganization('acme', 'ACME Corp', deletedAt: new \DateTimeImmutable());

        $this->client->loginUser($admin);
        $this->client->request('GET', '/organizations/acme');

        self::assertResponseIsSuccessful();
    }

    public function testListRequiresAuthentication(): void
    {
        $this->client->request('GET', '/organizations');

        self::assertResponseRedirects();
    }

    public function testListShowsOnlyOrganizationsOwnedByUser(): void
    {
        $owner = self::createUser(roles: ['ROLE_ADMIN_ORGS']);
        $other = self::createUser('other', 'other@example.org', roles: ['ROLE_ADMIN_ORGS']);
        $this->store($owner, $other);

        $this->persistOrganization('mine', 'Mine Org', owner: $owner);
        $this->persistOrganization('theirs', 'Their Org', owner: $other);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/organizations');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Mine Org', $body);
        self::assertStringNotContainsString('Their Org', $body);
    }

    public function testListShowsOnlyOrganizationsOwnedByAdmin(): void
    {
        // For now even a Packagist admin sees only the organizations they own
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN']);
        $other = self::createUser('other', 'other@example.org');
        $this->store($admin, $other);

        $this->persistOrganization('mine', 'Admin Org', owner: $admin);
        $this->persistOrganization('theirs', 'Their Org', owner: $other);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/organizations');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Admin Org', $body);
        self::assertStringNotContainsString('Their Org', $body);
    }

    public function testSettingsForbiddenForNonOwner(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $intruder = self::createUser('intruder', 'intruder@example.org');
        $this->store($owner, $intruder);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/organizations/acme/settings');

        self::assertResponseStatusCodeSame(403);
    }

    public function testSettingsRedirectsOwnerWithoutTwoFactorToSetup(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $this->store($owner);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($owner);
        $this->client->request('GET', '/organizations/acme/settings');

        // 2FA is required to manage an organization; an owner without it is guided to enable it
        // rather than shown a bare 403.
        self::assertResponseRedirects('/users/owner/2fa/');
    }

    public function testSettingsRendersPrefilledFormForOwner(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/organizations/acme/settings');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->selectButton('Save changes'));
        self::assertSame('ACME Corp', $crawler->filter('#organization_details_displayName')->attr('value'));
        self::assertSame('acme', $crawler->filter('#organization_details_slug')->attr('value'));
    }

    public function testOwnerRenamesViaSettings(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        // Create through the event store so the aggregate has a history to update.
        static::getService(OrganizationManager::class)->create($owner, $owner, 'acme', 'ACME Corp', null);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/organizations/acme/settings');

        $form = $crawler->selectButton('Save changes')->form([
            'organization_details[displayName]' => 'ACME Inc',
            'organization_details[slug]' => 'acme',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/organizations/acme/settings');

        $organization = $this->organizations()->findOneBySlug('acme');
        self::assertNotNull($organization);
        self::assertSame('ACME Inc', $organization->displayName);
    }

    public function testTeamsForbiddenForNonOwner(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $intruder = self::createUser('intruder', 'intruder@example.org');
        $this->store($owner, $intruder);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/organizations/acme/teams');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateTeamRedirectsOwnerWithoutTwoFactorToSetup(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $this->store($owner);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($owner);
        $this->client->request('GET', '/organizations/acme/teams/create');

        // An owner without 2FA is guided to enable it rather than shown a bare 403.
        self::assertResponseRedirects('/users/owner/2fa/');
    }

    public function testOwnerCreatesTeam(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        // Create through the event store so the aggregate has a bootstrapped history.
        static::getService(OrganizationManager::class)->create($owner,$owner, 'acme', 'ACME Corp', null);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/organizations/acme/teams/create');

        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Create team')->form(['team[name]' => 'backend']);
        $this->client->submit($form);

        self::assertResponseRedirects('/organizations/acme/teams');

        $organization = $this->organizations()->findOneBySlug('acme');
        self::assertNotNull($organization);
        $teams = static::getService(OrganizationTeamRepository::class)->findByOrg($organization->id);
        $names = array_map(static fn ($t): string => $t->name, $teams);
        self::assertContains('backend', $names);
    }

    public function testOwnerRenamesTeam(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        // Create through the event store so the aggregate has a bootstrapped history.
        static::getService(OrganizationManager::class)->create($owner, $owner,'acme', 'ACME Corp', null);
        $organization = $this->organizations()->findOneBySlug('acme');
        self::assertNotNull($organization);

        static::getService(OrganizationMembershipManager::class)->createTeam($organization, $owner, 'backend', null);
        $team = static::getService(OrganizationTeamRepository::class)->findByOrg($organization->id);
        $backend = null;
        foreach ($team as $candidate) {
            if ($candidate->name === 'backend') {
                $backend = $candidate;
            }
        }
        self::assertNotNull($backend);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', sprintf('/organizations/acme/teams/%s/rename', $backend->teamId));

        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Rename team')->form(['team[name]' => 'platform']);
        $this->client->submit($form);

        self::assertResponseRedirects('/organizations/acme/teams');

        $renamed = static::getService(OrganizationTeamRepository::class)->findOneByOrgAndTeamId($organization->id, $backend->teamId);
        self::assertNotNull($renamed);
        self::assertSame('platform', $renamed->name);
    }

    public function testRenameSystemTeamReturns404(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        static::getService(OrganizationManager::class)->create($owner, $owner,'acme', 'ACME Corp', null);
        $organization = $this->organizations()->findOneBySlug('acme');
        self::assertNotNull($organization);
        self::assertNotNull($organization->ownersTeamId);

        $this->client->loginUser($owner);
        $this->client->request('GET', sprintf('/organizations/acme/teams/%s/rename', $organization->ownersTeamId));

        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnerDeletesTeam(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        [$organization, $backend] = $this->createOrganizationWithCustomTeam($owner, 'acme', 'ACME Corp', 'backend');

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', sprintf('/organizations/acme/teams/%s/delete', $backend->teamId));

        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Delete team')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/organizations/acme/teams');

        $deleted = static::getService(OrganizationTeamRepository::class)->findOneByOrgAndTeamId($organization->id, $backend->teamId);
        self::assertNull($deleted);
    }

    public function testDeleteSystemTeamReturns404(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        static::getService(OrganizationManager::class)->create($owner, $owner, 'acme', 'ACME Corp', null);
        $organization = $this->organizations()->findOneBySlug('acme');
        self::assertNotNull($organization);

        $this->client->loginUser($owner);
        $this->client->request('GET', sprintf('/organizations/acme/teams/%s/delete', $organization->ownersTeamId));

        self::assertResponseStatusCodeSame(404);
    }

    public function testTeamFromAnotherOrganizationReturns404(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $other = self::createUser('other', 'other@example.org');
        $other->setTotpSecret('totp-secret');
        $this->store($owner, $other);

        static::getService(OrganizationManager::class)->create($owner, $owner, 'acme', 'ACME Corp', null);
        [, $foreignTeam] = $this->createOrganizationWithCustomTeam($other, 'globex', 'Globex', 'backend');

        $this->client->loginUser($owner);
        // The team belongs to globex, so it must not be reachable under acme.
        $this->client->request('GET', sprintf('/organizations/acme/teams/%s/rename', $foreignTeam->teamId));

        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnerAddsMemberToTeam(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        [$organization, $backend] = $this->createOrganizationWithCustomTeam($owner, 'acme', 'ACME Corp', 'backend');

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/add', $backend->teamId));

        self::assertResponseIsSuccessful();
        // The owner is already an org member (via the owners team), so they can be added to a custom team.
        $form = $crawler->selectButton('Add member')->form(['add_team_member[username]' => 'owner']);
        $this->client->submit($form);

        self::assertResponseRedirects('/organizations/acme/teams');

        $members = static::getService(OrganizationTeamMemberRepository::class)->findByTeam($backend->teamId);
        $userIds = array_map(static fn (OrganizationTeamMember $m): int => $m->userId, $members);
        self::assertContains($owner->getId(), $userIds);
    }

    public function testAddTeamMemberWithUnknownUserRerendersWithError(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        [, $backend] = $this->createOrganizationWithCustomTeam($owner, 'acme', 'ACME Corp', 'backend');

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/add', $backend->teamId));

        $form = $crawler->selectButton('Add member')->form(['add_team_member[username]' => 'ghost']);
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No member "ghost" was found in this organization.', $crawler->text());
    }

    public function testAddTeamMemberWithNonMemberUserRerendersWithError(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        // An existing platform user who has not joined the org cannot be added (joining is invitation-only).
        $outsider = self::createUser('outsider', 'outsider@example.org');
        $this->store($owner, $outsider);

        [, $backend] = $this->createOrganizationWithCustomTeam($owner, 'acme', 'ACME Corp', 'backend');

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/add', $backend->teamId));

        $form = $crawler->selectButton('Add member')->form(['add_team_member[username]' => 'outsider']);
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No member "outsider" was found in this organization.', $crawler->text());
    }

    public function testAddTeamMemberDoesNotAcceptEmailAddress(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        [, $backend] = $this->createOrganizationWithCustomTeam($owner, 'acme', 'ACME Corp', 'backend');

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/add', $backend->teamId));

        // The owner is a member, but they are matched by username only: their email must not resolve them.
        $form = $crawler->selectButton('Add member')->form(['add_team_member[username]' => 'owner@example.org']);
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No member "owner@example.org" was found in this organization.', $crawler->text());
    }

    public function testAddTeamMemberToUnknownTeamReturns404(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        static::getService(OrganizationManager::class)->create($owner, $owner, 'acme', 'ACME Corp', null);

        $this->client->loginUser($owner);
        $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/add', new Ulid()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnerRemovesMemberFromTeam(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        [$organization, $backend] = $this->createOrganizationWithCustomTeam($owner, 'acme', 'ACME Corp', 'backend');
        // The owner is already an org member (via the owners team), so they can be added to the custom team.
        static::getService(OrganizationMembershipManager::class)->addTeamMember($organization, $owner, $backend->teamId, $owner->getId(), null);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/owner/remove', $backend->teamId));

        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Remove member')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/organizations/acme/teams');

        $members = static::getService(OrganizationTeamMemberRepository::class)->findByTeam($backend->teamId);
        $userIds = array_map(static fn (OrganizationTeamMember $m): int => $m->userId, $members);
        self::assertNotContains($owner->getId(), $userIds);
    }

    public function testRemoveLastOwnerShowsExplanationAndNoForm(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        static::getService(OrganizationManager::class)->create($owner, $owner, 'acme', 'ACME Corp', null);
        $organization = $this->organizations()->findOneBySlug('acme');
        self::assertNotNull($organization);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/owner/remove', $organization->ownersTeamId));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('is the last owner of ACME Corp and cannot be removed', $crawler->text());
        self::assertCount(0, $crawler->selectButton('Remove member'));
    }

    public function testRemoveTeamMemberReturns404ForNonMemberOfOrg(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $intruder = self::createUser('intruder', 'intruder@example.org');
        $this->store($owner, $intruder);

        [$organization, $backend] = $this->createOrganizationWithCustomTeam($owner, 'acme', 'ACME Corp', 'backend');
        static::getService(OrganizationMembershipManager::class)->addTeamMember($organization, $owner, $backend->teamId, $owner->getId(), null);

        // A user who is not a member of the org cannot even see the team exists: the resolver's
        // read-access check turns this into a 404 before the owner-only guard is reached.
        $this->client->loginUser($intruder);
        $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/owner/remove', $backend->teamId));

        self::assertResponseStatusCodeSame(404);
    }

    public function testRemoveTeamMemberForbiddenForMemberWhoIsNotOwner(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $member = self::createUser('member', 'member@example.org');
        $this->store($owner, $member);
        $organization = $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        // A custom team with `owner` as its (removable) member, and `member` as an org member too.
        $team = new OrganizationTeam(new Ulid(), $organization, OrganizationTeamKind::Custom, 'backend', $owner, new \DateTimeImmutable());
        $ownerMembership = new OrganizationTeamMember($team->teamId, $owner->getId(), $organization->id, $owner, new \DateTimeImmutable());
        $memberMembership = new OrganizationTeamMember($team->teamId, $member->getId(), $organization->id, $member, new \DateTimeImmutable());
        $orgMember = new OrganizationMember($organization->id, $member->getId(), new \DateTimeImmutable());
        $this->store($team, $ownerMembership, $memberMembership, $orgMember);

        // A member of the org passes the resolver's read-access check, so the owner-only guard is
        // reached and denies with a 403.
        $this->client->loginUser($member);
        $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/owner/remove', $team->teamId));

        self::assertResponseStatusCodeSame(403);
    }

    public function testRemoveTeamMemberRedirectsOwnerWithoutTwoFactorToSetup(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $this->store($owner);

        [$organization, $backend] = $this->createOrganizationWithCustomTeam($owner, 'acme', 'ACME Corp', 'backend');
        static::getService(OrganizationMembershipManager::class)->addTeamMember($organization, $owner, $backend->teamId, $owner->getId(), null);

        $this->client->loginUser($owner);
        $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/owner/remove', $backend->teamId));

        // An owner without 2FA is guided to enable it rather than shown a bare 403.
        self::assertResponseRedirects('/users/owner/2fa/');
    }

    public function testRemoveUnknownTeamMemberReturns404(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        [, $backend] = $this->createOrganizationWithCustomTeam($owner, 'acme', 'ACME Corp', 'backend');

        $this->client->loginUser($owner);
        $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/ghost/remove', $backend->teamId));

        self::assertResponseStatusCodeSame(404);
    }

    public function testRemoveTeamMemberReturns404ForNonMemberUsername(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        // An existing user who is not a member of the team must not be reachable via the team member route.
        $outsider = self::createUser('outsider', 'outsider@example.org');
        $this->store($owner, $outsider);

        [, $backend] = $this->createOrganizationWithCustomTeam($owner, 'acme', 'ACME Corp', 'backend');

        $this->client->loginUser($owner);
        $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/outsider/remove', $backend->teamId));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAddMemberToAllMembersTeamReturns404(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        static::getService(OrganizationManager::class)->create($owner, $owner, 'acme', 'ACME Corp', null);
        $organization = $this->organizations()->findOneBySlug('acme');
        self::assertNotNull($organization);

        $this->client->loginUser($owner);
        $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/add', $organization->allMembersTeamId));

        // The all-members team's roster is managed automatically; it has no manual add flow.
        self::assertResponseStatusCodeSame(404);
    }

    public function testRemoveMemberFromAllMembersTeamReturns404(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        static::getService(OrganizationManager::class)->create($owner, $owner,  'acme', 'ACME Corp', null);
        $organization = $this->organizations()->findOneBySlug('acme');
        self::assertNotNull($organization);

        $this->client->loginUser($owner);
        $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/owner/remove', $organization->allMembersTeamId));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAllMembersTeamShownInTeamsListWithoutMemberControls(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        static::getService(OrganizationManager::class)->create($owner, $owner,  'acme', 'ACME Corp', null);
        $organization = $this->organizations()->findOneBySlug('acme');
        self::assertNotNull($organization);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/organizations/acme/teams');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('All organization members', $crawler->text());
        self::assertStringContainsString('Membership is managed automatically', $crawler->text());
        // No add/remove links point at the all-members team.
        self::assertCount(0, $crawler->filter(sprintf('a[href*="/teams/%s/members"]', $organization->allMembersTeamId)));
    }

    public function testMemberCanViewTeamsButCannotCreate(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $member = self::createUser('member', 'member@example.org');
        $this->store($owner, $member);
        $organization = $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        // Make `member` a member of the org through a custom team (not the owners team).
        $team = new OrganizationTeam(new Ulid(), $organization, OrganizationTeamKind::Custom, 'backend', $owner, new \DateTimeImmutable());
        $teamMember = new OrganizationTeamMember($team->teamId, $member->getId(), $organization->id, $member, new \DateTimeImmutable());
        $orgMember = new OrganizationMember($organization->id, $member->getId(), new \DateTimeImmutable());
        $this->store($team, $teamMember, $orgMember);

        $this->client->loginUser($member);

        // A member may view the teams list.
        $this->client->request('GET', '/organizations/acme/teams');
        self::assertResponseIsSuccessful();

        // But creating a team stays owner-only.
        $this->client->request('GET', '/organizations/acme/teams/create');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRemoveMemberReturns404ForNonMemberOfOrg(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $intruder = self::createUser('intruder', 'intruder@example.org');
        $this->store($owner, $intruder);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        // A user who is not a member of the org cannot enumerate its members: the resolver's
        // read-access check turns this into a 404 before the owner-only guard is reached.
        $this->client->loginUser($intruder);
        $this->client->request('GET', '/organizations/acme/members/owner/remove');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRemoveMemberForbiddenForMemberWhoIsNotOwner(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $member = self::createUser('member', 'member@example.org');
        $this->store($owner, $member);
        $organization = $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        // Make `member` a member of the org through a custom team (not the owners team).
        $team = new OrganizationTeam(new Ulid(), $organization, OrganizationTeamKind::Custom, 'backend', $owner, new \DateTimeImmutable());
        $teamMember = new OrganizationTeamMember($team->teamId, $member->getId(), $organization->id, $member, new \DateTimeImmutable());
        $orgMember = new OrganizationMember($organization->id, $member->getId(), new \DateTimeImmutable());
        $this->store($team, $teamMember, $orgMember);

        // A member of the org passes the resolver's read-access check, so the owner-only guard is
        // reached and denies with a 403.
        $this->client->loginUser($member);
        $this->client->request('GET', '/organizations/acme/members/owner/remove');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRemoveMemberRedirectsOwnerWithoutTwoFactorToSetup(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $this->store($owner);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($owner);
        $this->client->request('GET', '/organizations/acme/members/owner/remove');

        // An owner without 2FA is guided to enable it rather than shown a bare 403.
        self::assertResponseRedirects('/users/owner/2fa/');
    }

    public function testRemoveUnknownMemberReturns404(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($owner);
        $this->client->request('GET', '/organizations/acme/members/ghost/remove');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRemoveMemberReturns404ForNonMemberUsername(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        // An existing user who is not a member of the org must not be reachable via the member route.
        $outsider = self::createUser('outsider', 'outsider@example.org');
        $this->store($owner, $outsider);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($owner);
        $this->client->request('GET', '/organizations/acme/members/outsider/remove');

        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnerSeesRemoveMemberConfirmationPage(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $member = self::createUser('member', 'member@example.org');
        $this->store($owner, $member);
        $organization = $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        // Make `member` a member of the org through a custom team.
        $team = new OrganizationTeam(new Ulid(), $organization, OrganizationTeamKind::Custom, 'backend', $owner, new \DateTimeImmutable());
        $teamMember = new OrganizationTeamMember($team->teamId, $member->getId(), $organization->id, $member, new \DateTimeImmutable());
        $orgMember = new OrganizationMember($organization->id, $member->getId(), new \DateTimeImmutable());
        $this->store($team, $teamMember, $orgMember);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/organizations/acme/members/member/remove');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->selectButton('Remove member'));
    }

    public function testRemoveLastOwnerFromOrganizationShowsExplanationAndNoForm(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/organizations/acme/members/owner/remove');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('is the last owner of ACME Corp and cannot be removed', $crawler->text());
        self::assertCount(0, $crawler->selectButton('Remove member'));
    }

    public function testMemberSeesLeaveConfirmationPage(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $this->store($owner);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/organizations/acme/members/leave');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->selectButton('Leave organization'));
    }

    public function testLeaveForbiddenForNonMember(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $outsider = self::createUser('outsider', 'outsider@example.org');
        $this->store($owner, $outsider);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($outsider);
        $this->client->request('GET', '/organizations/acme/members/leave');

        self::assertResponseStatusCodeSame(403);
    }

    public function testLeaveShowsErrorWhenLastOwnerTriesToLeave(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $this->store($owner);
        static::getService(OrganizationManager::class)->create($owner, $owner, 'acme', 'ACME Corp', null);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/organizations/acme/members/leave');

        $form = $crawler->selectButton('Leave organization')->form();
        $crawler = $this->client->submit($form);

        // The sole owner cannot leave: the form re-renders with the domain error instead of redirecting.
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The last owner cannot leave or be removed from the organization.', $crawler->text());
    }

    public function testShowRedirectsOldSlugToCurrentSlug(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $this->store($owner);

        $this->renameOrganization($owner, 'acme', 'acme-inc');

        $this->client->loginUser($owner);
        $this->client->request('GET', '/organizations/acme');

        // The old slug redirects (temporarily) to the current slug while the reservation is active.
        self::assertResponseRedirects('/organizations/acme-inc', 302);
    }

    public function testRedirectPreservesTheRouteForOldSlug(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        $this->renameOrganization($owner, 'acme', 'acme-inc');

        $this->client->loginUser($owner);
        $this->client->request('GET', '/organizations/acme/settings');

        self::assertResponseRedirects('/organizations/acme-inc/settings', 302);
    }

    public function testAuditLogShowsOnlyRecordsForThisOrganization(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);
        $organization = $this->persistOrganization('acme', 'ACME Corp', owner: $owner);
        $otherOrganization = $this->persistOrganization('globex', 'Globex', owner: $owner);

        $this->store(
            AuditRecord::organizationCreated($organization->id, $organization->slug, $organization->displayName, $owner),
            AuditRecord::organizationNameChanged($organization->id, $organization->slug, 'ACME Inc', 'ACME Corp', $owner),
            // Belongs to a different organization and must not leak into this page.
            AuditRecord::organizationCreated($otherOrganization->id, $otherOrganization->slug, $otherOrganization->displayName, $owner),
        );

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/organizations/acme/audit-log');
        self::assertResponseIsSuccessful();

        $types = $crawler->filter('[data-test=audit-log-type]')->each(fn ($element) => trim($element->text()));
        self::assertCount(2, $types, 'Only the two records for this organization should be listed');
    }

    public function testAuditLogTypeFilterNarrowsResults(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);
        $organization = $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->store(
            AuditRecord::organizationCreated($organization->id, $organization->slug, $organization->displayName, $owner),
            AuditRecord::organizationNameChanged($organization->id, $organization->slug, 'ACME Inc', 'ACME Corp', $owner),
        );

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/organizations/acme/audit-log?type[]='.AuditRecordType::OrganizationNameChanged->value);
        self::assertResponseIsSuccessful();

        $types = $crawler->filter('[data-test=audit-log-type]')->each(fn ($element) => trim($element->text()));
        self::assertCount(1, $types, 'The type filter should narrow the results to a single record');
    }

    public function testAuditLogForbiddenForNonOwner(): void
    {
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $stranger = self::createUser('stranger', 'stranger@example.org');
        $this->store($owner, $stranger);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($stranger);
        $this->client->request('GET', '/organizations/acme/audit-log');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Creates an organization and renames its slug through the event store, leaving an active
     * `RenamedFrom` reservation for the old slug.
     */
    private function renameOrganization(User $owner, string $from, string $to): void
    {
        $manager = static::getService(OrganizationManager::class);
        $manager->create($owner, $owner, $from, 'ACME Corp', null);

        $organization = $this->organizations()->findOneBySlug($from);
        self::assertNotNull($organization);

        $manager->edit($organization, $owner, $to, 'ACME Corp', null);
    }

    /**
     * Bootstraps an organization through the event store and creates a custom (non-system) team.
     *
     * @return array{Organization, OrganizationTeam}
     */
    private function createOrganizationWithCustomTeam(User $owner, string $slug, string $displayName, string $teamName): array
    {
        static::getService(OrganizationManager::class)->create($owner, $owner,$slug, $displayName, null);
        $organization = $this->organizations()->findOneBySlug($slug);
        self::assertNotNull($organization);

        static::getService(OrganizationMembershipManager::class)->createTeam($organization, $owner, $teamName, null);
        foreach (static::getService(OrganizationTeamRepository::class)->findByOrg($organization->id) as $team) {
            if ($team->name === $teamName) {
                return [$organization, $team];
            }
        }

        self::fail(sprintf('Team "%s" was not created.', $teamName));
    }

    private function persistOrganization(string $slug, string $displayName, ?User $owner = null, ?\DateTimeImmutable $deletedAt = null): Organization
    {
        $organization = self::createOrganization($slug, $displayName, $deletedAt);

        $this->store($organization);

        if ($owner !== null) {
            $this->store(self::createOwnerMembership($organization, $owner));
        }

        return $organization;
    }

    private function organizations(): OrganizationRepository
    {
        return static::getService(OrganizationRepository::class);
    }
}
