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

use App\Entity\Organization;
use App\Entity\OrganizationRepository;
use App\Entity\OrganizationTeam;
use App\Entity\OrganizationTeamKind;
use App\Entity\OrganizationTeamMember;
use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\OrganizationTeamRepository;
use App\Entity\User;
use App\Organization\OrganizationManager;
use App\Organization\OrganizationMembershipManager;
use App\Tests\IntegrationTestCase;
use Symfony\Component\Uid\Ulid;

class OrganizationControllerTest extends IntegrationTestCase
{
    public function testShowRendersActiveOrganization(): void
    {
        $user = $this->persistUser();
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
        $admin = self::persistUser('ROLE_ADMIN');
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
        $owner = self::persistUser();
        $other = self::createUser('other', 'other@example.org');
        $this->store($other);

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
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ADMIN_ORGS']);
        $intruder = self::createUser('intruder', 'intruder@example.org');
        $this->store($owner, $intruder);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/organizations/acme/settings');

        self::assertResponseStatusCodeSame(403);
    }

    public function testSettingsRedirectsOwnerWithoutTwoFactor(): void
    {
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ADMIN_ORGS']);
        $this->store($owner);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($owner);
        $this->client->request('GET', '/organizations/acme/settings');

        // 2FA is required to manage an organization.
        self::assertResponseRedirects();
    }

    public function testSettingsRendersPrefilledFormForOwner(): void
    {
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ADMIN_ORGS']);
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
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ADMIN_ORGS']);
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
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ORGANIZATIONS']);
        $intruder = self::createUser('intruder', 'intruder@example.org', roles: ['ROLE_ORGANIZATIONS']);
        $this->store($owner, $intruder);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/organizations/acme/teams');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateTeamRedirectsOwnerWithoutTwoFactor(): void
    {
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ORGANIZATIONS']);
        $this->store($owner);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($owner);
        $this->client->request('GET', '/organizations/acme/teams/create');

        self::assertResponseRedirects();
    }

    public function testOwnerCreatesTeam(): void
    {
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ORGANIZATIONS']);
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
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ORGANIZATIONS']);
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
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ORGANIZATIONS']);
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

    public function testTeamFromAnotherOrganizationReturns404(): void
    {
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ORGANIZATIONS']);
        $owner->setTotpSecret('totp-secret');
        $other = self::createUser('other', 'other@example.org', roles: ['ROLE_ORGANIZATIONS']);
        $other->setTotpSecret('totp-secret');
        $this->store($owner, $other);

        static::getService(OrganizationManager::class)->create($owner, 'acme', 'ACME Corp', null);
        [, $foreignTeam] = $this->createOrganizationWithCustomTeam($other, 'globex', 'Globex', 'backend');

        $this->client->loginUser($owner);
        // The team belongs to globex, so it must not be reachable under acme.
        $this->client->request('GET', sprintf('/organizations/acme/teams/%s/rename', $foreignTeam->teamId));

        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnerAddsMemberToTeam(): void
    {
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ORGANIZATIONS']);
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
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ORGANIZATIONS']);
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        [, $backend] = $this->createOrganizationWithCustomTeam($owner, 'acme', 'ACME Corp', 'backend');

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/add', $backend->teamId));

        $form = $crawler->selectButton('Add member')->form(['add_team_member[username]' => 'ghost']);
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No user "ghost" was found.', $crawler->text());
    }

    public function testAddTeamMemberToUnknownTeamReturns404(): void
    {
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ORGANIZATIONS']);
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        static::getService(OrganizationManager::class)->create($owner, $owner, 'acme', 'ACME Corp', null);

        $this->client->loginUser($owner);
        $this->client->request('GET', sprintf('/organizations/acme/teams/%s/members/add', new Ulid()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testMemberCanViewTeamsButCannotCreate(): void
    {
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ORGANIZATIONS']);
        $member = self::createUser('member', 'member@example.org', roles: ['ROLE_ORGANIZATIONS']);
        $this->store($owner, $member);
        $organization = $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        // Make `member` a member of the org through a custom team (not the owners team).
        $team = new OrganizationTeam(new Ulid(), $organization, OrganizationTeamKind::Custom, 'backend', $owner, new \DateTimeImmutable());
        $teamMember = new OrganizationTeamMember($team->teamId, $member->getId(), $organization->id, $member, new \DateTimeImmutable());
        $this->store($team, $teamMember);

        $this->client->loginUser($member);

        // A member may view the teams list.
        $this->client->request('GET', '/organizations/acme/teams');
        self::assertResponseIsSuccessful();

        // But creating a team stays owner-only.
        $this->client->request('GET', '/organizations/acme/teams/create');
        self::assertResponseStatusCodeSame(403);
    }

    public function testShowRedirectsOldSlugToCurrentSlug(): void
    {
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ADMIN_ORGS']);
        $this->store($owner);

        $this->renameOrganization($owner, 'acme', 'acme-inc');

        $this->client->loginUser($owner);
        $this->client->request('GET', '/organizations/acme');

        // The old slug redirects (temporarily) to the current slug while the reservation is active.
        self::assertResponseRedirects('/organizations/acme-inc', 302);
    }

    public function testRedirectPreservesTheRouteForOldSlug(): void
    {
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ADMIN_ORGS']);
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        $this->renameOrganization($owner, 'acme', 'acme-inc');

        $this->client->loginUser($owner);
        $this->client->request('GET', '/organizations/acme/settings');

        self::assertResponseRedirects('/organizations/acme-inc/settings', 302);
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
        $organization = self::createOrganization($slug, $displayName, $owner, $deletedAt);

        $this->store($organization);

        if ($owner !== null) {
            $this->store(self::createOwnerMembership($organization, $owner));
        }

        return $organization;
    }

    private function persistUser(string $role = 'ROLE_ADMIN_ORGS'): User
    {
        $user = self::createUser('admin', 'admin@example.org', roles: [$role]);
        $this->store($user);

        return $user;
    }

    private function organizations(): OrganizationRepository
    {
        return static::getService(OrganizationRepository::class);
    }
}
