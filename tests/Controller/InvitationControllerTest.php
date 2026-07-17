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
use App\Entity\OrganizationInvitationRepository;
use App\Entity\OrganizationRepository;
use App\Entity\OrganizationTeam;
use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\OrganizationTeamRepository;
use App\Entity\User;
use App\Organization\Domain\InvitationStatus;
use App\Organization\OrganizationManager;
use App\Organization\OrganizationMembershipManager;
use App\Tests\IntegrationTestCase;
use Symfony\Component\Uid\Ulid;

class InvitationControllerTest extends IntegrationTestCase
{
    public function testOwnerInvitesMemberAndEmailIsSent(): void
    {
        [$owner, , $backend] = $this->orgWithTeam();

        $this->client->loginUser($owner);
        $this->submitInvite($backend, 'alice@example.org');

        self::assertResponseRedirects('/organizations/acme/invitations');
        self::assertEmailCount(1);
        self::assertStringContainsString('/organizations/acme/invitations/', self::getMailerMessages()[0]->getTextBody() ?? '');
    }

    public function testOwnerSeesInvitationInManagementList(): void
    {
        [$owner, , $backend] = $this->orgWithTeam();

        $this->client->loginUser($owner);
        $this->submitInvite($backend, 'alice@example.org');

        $crawler = $this->client->request('GET', '/organizations/acme/invitations');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('alice@example.org', $crawler->text());
        self::assertCount(1, $crawler->selectButton('Resend'));
        self::assertCount(1, $crawler->selectButton('Revoke'));
    }

    public function testOwnerRevokesInvitationFromList(): void
    {
        [$owner, $organization, $backend] = $this->orgWithTeam();

        $this->client->loginUser($owner);
        $this->submitInvite($backend, 'alice@example.org');

        $crawler = $this->client->request('GET', '/organizations/acme/invitations');
        $this->client->submit($crawler->selectButton('Revoke')->form());

        self::assertResponseRedirects('/organizations/acme/invitations');

        $rows = static::getService(OrganizationInvitationRepository::class)->findByOrg($organization->id);
        self::assertCount(1, $rows);
        self::assertSame(InvitationStatus::Revoked, $rows[0]->status);
    }

    public function testInviteeAcceptsThroughLink(): void
    {
        [$owner, $organization, $backend] = $this->orgWithTeam();
        $alice = self::createUser('alice', 'alice@example.org');
        $this->store($alice);

        $this->client->loginUser($owner);
        $this->submitInvite($backend, 'alice@example.org');
        $path = $this->acceptUrlPath();

        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->selectButton('Accept invitation'));

        $this->client->submit($crawler->selectButton('Accept invitation')->form());
        self::assertResponseRedirects('/');

        self::assertTrue(
            static::getService(OrganizationTeamMemberRepository::class)->isMemberOfOrg($organization->id, $alice->getId()),
        );
    }

    public function testInviteeDeclinesThroughLink(): void
    {
        [$owner, $organization, $backend] = $this->orgWithTeam();
        $alice = self::createUser('alice', 'alice@example.org');
        $this->store($alice);

        $this->client->loginUser($owner);
        $this->submitInvite($backend, 'alice@example.org');
        $path = $this->acceptUrlPath();

        $this->client->loginUser($alice);
        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->selectButton('Decline')->form());

        self::assertResponseRedirects('/');
        self::assertFalse(
            static::getService(OrganizationTeamMemberRepository::class)->isMemberOfOrg($organization->id, $alice->getId()),
        );
    }

    public function testInvalidTokenReturns403(): void
    {
        [$owner, , $backend] = $this->orgWithTeam();
        $alice = self::createUser('alice', 'alice@example.org');
        $this->store($alice);

        $this->client->loginUser($owner);
        $this->submitInvite($backend, 'alice@example.org');
        $path = $this->acceptUrlPath();

        // Tamper with the last character of the 64-hex token.
        $tampered = substr($path, 0, -1) . ($path[-1] === 'a' ? 'b' : 'a');

        $this->client->loginUser($alice);
        $this->client->request('GET', $tampered);

        self::assertResponseStatusCodeSame(403);
    }

    public function testEmailMismatchHidesAcceptButton(): void
    {
        [$owner, , $backend] = $this->orgWithTeam();
        $mallory = self::createUser('mallory', 'mallory@example.org');
        $this->store($mallory);

        $this->client->loginUser($owner);
        $this->submitInvite($backend, 'alice@example.org');
        $path = $this->acceptUrlPath();

        $this->client->loginUser($mallory);
        $crawler = $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectButton('Accept invitation'));
        self::assertStringContainsString('different email address', $crawler->text());
    }

    public function testLinkRequiresAuthentication(): void
    {
        // The invitee link requires a logged-in user; security runs before any token validation, so an
        // anonymous visitor is redirected to log in regardless of the (here dummy) invitation and token.
        $path = sprintf('/organizations/acme/invitations/%s/%s', (new Ulid())->toBase32(), str_repeat('a', 64));
        $this->client->request('GET', $path);

        self::assertResponseRedirects();
    }

    private function submitInvite(OrganizationTeam $team, string $email): void
    {
        $crawler = $this->client->request('GET', '/organizations/acme/invitations/invite');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Send invitation')->form();
        $values = $form->getPhpValues();
        $values['invite_member']['email'] = $email;
        $values['invite_member']['teamIds'] = [$team->teamId->toRfc4122()];

        $this->client->request('POST', $form->getUri(), $values);
    }

    private function acceptUrlPath(): string
    {
        $body = self::getMailerMessages()[0]->getTextBody() ?? '';
        self::assertSame(1, preg_match('#https?://[^\s]+/invitations/[^\s]+#', $body, $matches));

        return (string) parse_url($matches[0], PHP_URL_PATH);
    }

    /**
     * @return array{User, Organization, OrganizationTeam}
     */
    private function orgWithTeam(): array
    {
        $owner = self::createUser('owner', 'owner@example.org', roles: ['ROLE_ADMIN_ORGS']);
        $owner->setTotpSecret('totp-secret');
        $this->store($owner);

        static::getService(OrganizationManager::class)->create($owner, 'acme', 'ACME Corp', null);
        $organization = static::getService(OrganizationRepository::class)->findOneBySlug('acme');
        self::assertNotNull($organization);

        static::getService(OrganizationMembershipManager::class)->createTeam($organization, $owner, 'backend', null);
        foreach (static::getService(OrganizationTeamRepository::class)->findByOrg($organization->id) as $team) {
            if ($team->name === 'backend') {
                return [$owner, $organization, $team];
            }
        }

        self::fail('backend team was not created.');
    }
}
