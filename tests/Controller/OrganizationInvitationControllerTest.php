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
use App\Entity\OrganizationInvitation;
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

class OrganizationInvitationControllerTest extends IntegrationTestCase
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
        // The invitation's team is resolved and rendered (via the batched team lookup).
        self::assertStringContainsString('backend', $crawler->filter('.team-labels')->text());
        self::assertCount(1, $crawler->filter('a:contains("Resend")'));
        self::assertCount(1, $crawler->selectButton('Revoke'));
    }

    public function testOwnerResendsInvitationFromDedicatedPage(): void
    {
        [$owner, $organization, $backend] = $this->orgWithTeam();

        $this->client->loginUser($owner);
        $this->submitInvite($backend, 'alice@example.org');
        $before = $this->pendingInvitation($organization);

        // The list links to a dedicated resend page that explains the consequences.
        $crawler = $this->client->request('GET', '/organizations/acme/invitations');
        $crawler = $this->client->click($crawler->filter('a:contains("Resend")')->link());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('invalidate the previous invitation link', $crawler->text());
        self::assertStringContainsString('another 7 days', $crawler->text());

        $this->client->submit($crawler->selectButton('Resend invitation')->form());
        self::assertResponseRedirects('/organizations/acme/invitations');

        // A fresh link is sent (one email per request) and the expiry is pushed out.
        self::assertEmailCount(1);
        $after = $this->pendingInvitation($organization);
        self::assertSame(InvitationStatus::Pending, $after->status);
        self::assertGreaterThan($before->expiresAt, $after->expiresAt);
        self::assertNotSame($before->tokenHash, $after->tokenHash);
    }

    public function testResendPageRejectsExpiredInvitation(): void
    {
        [$owner, $organization, $backend] = $this->orgWithTeam();

        $this->client->loginUser($owner);
        $this->submitInvite($backend, 'alice@example.org');

        $invitation = $this->pendingInvitation($organization);
        $invitation->expiresAt = new \DateTimeImmutable('-1 day');
        static::getEM()->flush();

        $this->client->request('GET', sprintf('/organizations/acme/invitations/%s/resend', $invitation->id->toBase32()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testResendPageRejectsResolvedInvitation(): void
    {
        [$owner, $organization, $backend] = $this->orgWithTeam();

        $this->client->loginUser($owner);
        $this->submitInvite($backend, 'alice@example.org');

        $invitation = $this->pendingInvitation($organization);
        $invitation->status = InvitationStatus::Revoked;
        static::getEM()->flush();

        $this->client->request('GET', sprintf('/organizations/acme/invitations/%s/resend', $invitation->id->toBase32()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testResendPageRejectsInvitationFromAnotherOrganization(): void
    {
        [$owner, $organization, $backend] = $this->orgWithTeam();

        $this->client->loginUser($owner);
        $this->submitInvite($backend, 'alice@example.org');
        $invitation = $this->pendingInvitation($organization);

        // A second organization the same admin owns; the invitation belongs to acme, not beta.
        static::getService(OrganizationManager::class)->create($owner, $owner, 'beta', 'Beta Corp', null);

        $this->client->request('GET', sprintf('/organizations/beta/invitations/%s/resend', $invitation->id->toBase32()));

        self::assertResponseStatusCodeSame(404);
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

    public function testExpiredInvitationLinkReturns404(): void
    {
        [, $organization] = $this->orgWithTeam();
        $alice = self::createUser('alice', 'alice@example.org');
        $this->store($alice);

        // A pending-but-expired invitation is no longer active, so the resolver rejects the link.
        $invitation = new OrganizationInvitation(
            new Ulid(),
            $organization->id,
            'alice@example.org',
            'alice@example.org',
            InvitationStatus::Pending,
            'hash',
            new \DateTimeImmutable('-8 days'),
            new \DateTimeImmutable('-1 day'),
            new \DateTimeImmutable('-8 days'),
            null,
        );
        $this->store($invitation);

        $this->client->loginUser($alice);
        $this->client->request('GET', sprintf('/organizations/acme/invitations/%s/%s', $invitation->id->toBase32(), str_repeat('a', 64)));

        self::assertResponseStatusCodeSame(404);
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

    public function testInvalidTokenReturns404(): void
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

        // A bad token is indistinguishable from a missing/expired invitation: both are 404.
        self::assertResponseStatusCodeSame(404);
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
        // anonymous visitor is redirected to log in regardless of the (here dummy) token. The organization
        // and invitation are resolved into the action before the auth check runs, so both must exist.
        [, $organization] = $this->orgWithTeam();
        $invitation = new OrganizationInvitation(
            new Ulid(),
            $organization->id,
            'alice@example.org',
            'alice@example.org',
            InvitationStatus::Pending,
            'hash',
            new \DateTimeImmutable(),
            new \DateTimeImmutable('+7 days'),
            new \DateTimeImmutable(),
            null,
        );
        $this->store($invitation);

        $path = sprintf('/organizations/acme/invitations/%s/%s', $invitation->id->toBase32(), str_repeat('a', 64));
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

    private function pendingInvitation(Organization $organization): OrganizationInvitation
    {
        $rows = static::getService(OrganizationInvitationRepository::class)->findByOrg($organization->id);
        self::assertCount(1, $rows);

        return $rows[0];
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

        static::getService(OrganizationManager::class)->create($owner, $owner, 'acme', 'ACME Corp', null);
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
