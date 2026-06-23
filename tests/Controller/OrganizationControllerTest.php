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
use App\Entity\OrganizationStatus;
use App\Entity\User;
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

    public function testListShowsAllOrganizationsForAdmin(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN']);
        $other = self::createUser('other', 'other@example.org');
        $this->store($admin, $other);

        $this->persistOrganization('theirs', 'Their Org', owner: $other);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/organizations');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Their Org', $crawler->filter('body')->text());
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
            'create_organization[displayName]' => 'Composer',
            'create_organization[slug]' => 'composer',
        ]);
        $crawler = $this->client->submit($form);

        // The OrganizationException is caught and surfaced as a form error, not a 500.
        self::assertResponseIsSuccessful();
        $this->assertFormError('"composer" is a reserved name and cannot be used.', 'create_organization', $crawler);
        self::assertNull($this->organizations()->findOneBySlug('composer'));
    }

    private function createAdminWithTwoFactor(): User
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN']);
        $admin->setTotpSecret('totp-secret');

        return $admin;
    }

    private function persistOrganization(string $slug, string $displayName, ?User $owner = null, ?\DateTimeImmutable $deletedAt = null): Organization
    {
        $organization = new Organization(
            new Ulid(),
            $slug,
            $displayName,
            $deletedAt !== null ? OrganizationStatus::Deleted : OrganizationStatus::Active,
            new \DateTimeImmutable(),
            $owner,
            $deletedAt,
            $deletedAt !== null ? 'owner' : null,
        );

        $this->store($organization);

        return $organization;
    }

    private function persistUser(string $role = 'ROLE_ORGANIZATIONS'): User
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
