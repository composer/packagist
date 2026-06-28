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
use App\Entity\User;
use App\Organization\OrganizationManager;
use App\Tests\IntegrationTestCase;

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

    public function testCreateIsForbiddenForNonAdmin(): void
    {
        $user = self::createUser('regular', 'regular@example.org');
        $this->store($user);

        $this->client->loginUser($user);
        $this->client->request('GET', '/organizations/create');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateRedirectsWhenTwoFactorNotEnabled(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN']);
        $this->store($admin);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/organizations/create');

        // 2FA is required to create an organization / become an owner.
        self::assertResponseRedirects();
        self::assertNull($this->organizations()->findOneBySlug('acme'));
    }

    public function testCreateRendersForm(): void
    {
        $admin = $this->createAdminWithTwoFactor();
        $this->store($admin);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/organizations/create');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->selectButton('Create organization'));
    }

    public function testCreatePersistsOrganizationAndRedirects(): void
    {
        $admin = $this->createAdminWithTwoFactor();
        $this->store($admin);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/organizations/create');

        $form = $crawler->selectButton('Create organization')->form([
            'create_organization[displayName]' => 'ACME Corp',
            'create_organization[slug]' => 'acme',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/organizations/acme');

        $organization = $this->organizations()->findOneBySlug('acme');
        self::assertNotNull($organization);
        self::assertSame('ACME Corp', $organization->displayName);
        self::assertSame($admin->getId(), $organization->createdBy?->getId());
    }

    public function testCreateRendersFormErrorForReservedSlug(): void
    {
        $admin = $this->createAdminWithTwoFactor();
        $this->store($admin);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/organizations/create');

        $form = $crawler->selectButton('Create organization')->form([
            'create_organization[displayName]' => 'Acme Corp',
            'create_organization[slug]' => 'composer',
        ]);
        $crawler = $this->client->submit($form);

        // A reserved slug is rejected by form validation and surfaced as a form error, not a 500.
        self::assertResponseIsSuccessful();
        $this->assertFormError('"composer" is a reserved name and cannot be used.', 'create_organization', $crawler);
        self::assertNull($this->organizations()->findOneBySlug('composer'));
    }

    public function testSettingsForbiddenForNonOwner(): void
    {
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ORGANIZATIONS']);
        $intruder = self::createUser('intruder', 'intruder@example.org', roles: ['ROLE_ORGANIZATIONS']);
        $this->store($owner, $intruder);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/organizations/acme/settings');

        self::assertResponseStatusCodeSame(403);
    }

    public function testSettingsRedirectsOwnerWithoutTwoFactor(): void
    {
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ORGANIZATIONS']);
        $this->store($owner);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($owner);
        $this->client->request('GET', '/organizations/acme/settings');

        // 2FA is required to manage an organization.
        self::assertResponseRedirects();
    }

    public function testSettingsRendersPrefilledFormForOwner(): void
    {
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ORGANIZATIONS']);
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);
        $this->persistOrganization('acme', 'ACME Corp', owner: $owner);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/organizations/acme/settings');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->selectButton('Save changes'));
        self::assertSame('ACME Corp', $crawler->filter('#edit_organization_displayName')->attr('value'));
        self::assertSame('acme', $crawler->filter('#edit_organization_slug')->attr('value'));
    }

    public function testOwnerRenamesViaSettings(): void
    {
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ORGANIZATIONS']);
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        // Create through the event store so the aggregate has a history to update.
        static::getContainer()->get(OrganizationManager::class)->create($owner, 'acme', 'ACME Corp', null);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/organizations/acme/settings');

        $form = $crawler->selectButton('Save changes')->form([
            'edit_organization[displayName]' => 'ACME Inc',
            'edit_organization[slug]' => 'acme',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/organizations/acme/settings');

        $organization = $this->organizations()->findOneBySlug('acme');
        self::assertNotNull($organization);
        self::assertSame('ACME Inc', $organization->displayName);
    }

    private function createAdminWithTwoFactor(): User
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN']);
        $admin->setTotpSecret('totp-secret');

        return $admin;
    }

    private function persistOrganization(string $slug, string $displayName, ?User $owner = null, ?\DateTimeImmutable $deletedAt = null): Organization
    {
        $organization = self::createOrganization($slug, $displayName, $owner, $deletedAt);

        $this->store($organization);

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
        return static::getContainer()->get(OrganizationRepository::class);
    }
}
