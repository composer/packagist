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

namespace App\Tests\Controller\Admin;

use App\Entity\OrganizationRepository;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;

class OrganizationControllerTest extends IntegrationTestCase
{
    public function testListIsForbiddenForNonAdmin(): void
    {
        $user = self::createUser('regular', 'regular@example.org', roles: ['ROLE_USER']);
        $this->store($user);

        $this->client->loginUser($user);
        $this->client->request('GET', '/admin/organizations');

        self::assertResponseStatusCodeSame(403);
    }

    public function testListShowsAllOrganizations(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN_ORGS']);
        $other = self::createUser('other', 'other@example.org');
        $this->store($admin, $other);

        $this->store(
            $adminOrg = self::createOrganization('mine', 'Admin Org'),
            $theirOrg = self::createOrganization('theirs', 'Their Org'),
            ...self::createOwnerMembership($adminOrg, $admin),
            ...self::createOwnerMembership($theirOrg, $other),
        );

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/organizations');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('Admin Org', $body);
        self::assertStringContainsString('Their Org', $body);
    }

    public function testCreateRendersForm(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN_ORGS']);
        $this->store($admin);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/organizations/create');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->selectButton('Create organization'));
    }

    public function testCreatePersistsOrganizationForSelectedOwnerAndRedirects(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN_ORGS']);
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($admin, $owner);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/organizations/create');

        $form = $crawler->selectButton('Create organization')->form([
            'admin_create_organization[owner]' => 'owner',
            'admin_create_organization[displayName]' => 'ACME Corp',
            'admin_create_organization[slug]' => 'acme',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/organizations');

        $organization = $this->organizations()->findOneBySlug('acme');
        self::assertNotNull($organization);
        self::assertSame('ACME Corp', $organization->displayName);

        // The acting admin, not the owner, is recorded as the actor on the transparency log.
        $connection = static::getService(Connection::class);
        $actorId = $connection->fetchOne("SELECT actorId FROM audit_log WHERE type = 'organization_created'");
        self::assertSame($admin->getId(), (int) $actorId);
    }

    public function testCreateRejectsUnknownOwner(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN_ORGS']);
        $this->store($admin);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/organizations/create');

        $form = $crawler->selectButton('Create organization')->form([
            'admin_create_organization[owner]' => 'nobody',
            'admin_create_organization[displayName]' => 'ACME Corp',
            'admin_create_organization[slug]' => 'acme',
        ]);
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        $this->assertFormError('No user with this username or email address exists.', 'admin_create_organization', $crawler);
        self::assertNull($this->organizations()->findOneBySlug('acme'));
    }

    public function testCreateRejectsOwnerWithoutTwoFactor(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN_ORGS']);
        $owner = self::createUser('owner', 'owner@example.org');
        $this->store($admin, $owner);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/organizations/create');

        $form = $crawler->selectButton('Create organization')->form([
            'admin_create_organization[owner]' => 'owner',
            'admin_create_organization[displayName]' => 'ACME Corp',
            'admin_create_organization[slug]' => 'acme',
        ]);
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        $this->assertFormError('The selected owner must enable two-factor authentication before becoming an organization owner.', 'admin_create_organization', $crawler);
        self::assertNull($this->organizations()->findOneBySlug('acme'));
    }

    public function testCreateRendersFormErrorForReservedSlug(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN_ORGS']);
        $owner = self::createUser('owner', 'owner@example.org');
        $owner->setTotpSecret('totp-secret');
        $this->store($admin, $owner);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/organizations/create');

        $form = $crawler->selectButton('Create organization')->form([
            'admin_create_organization[owner]' => 'owner',
            'admin_create_organization[displayName]' => 'Acme Corp',
            'admin_create_organization[slug]' => 'composer',
        ]);
        $crawler = $this->client->submit($form);

        // A reserved slug is rejected by validation and surfaced as a form error, not a 500.
        self::assertResponseIsSuccessful();
        $this->assertFormError('"composer" is a reserved name and cannot be used.', 'admin_create_organization', $crawler);
        self::assertNull($this->organizations()->findOneBySlug('composer'));
    }

    private function organizations(): OrganizationRepository
    {
        return static::getContainer()->get(OrganizationRepository::class);
    }
}
