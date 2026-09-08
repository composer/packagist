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

namespace App\Tests\Command;

use App\Command\ExpireOrganizationInvitationsCommand;
use App\Entity\Organization as OrganizationReadModel;
use App\Entity\OrganizationInvitation;
use App\Entity\OrganizationInvitationRepository;
use App\Entity\User;
use App\Organization\Domain\InvitationStatus;
use App\Organization\InvitationManager;
use App\Organization\OrganizationManager;
use App\Entity\OrganizationRepository;
use App\Service\Locker;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;

class ExpireOrganizationInvitationsCommandTest extends IntegrationTestCase
{
    private CommandTester $commandTester;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        // Drive both the manager and the command off one mock so we can move past the invitation expiry.
        Clock::set($this->clock = new MockClock('2024-01-01 00:00:00'));

        $command = new ExpireOrganizationInvitationsCommand(
            static::getService(OrganizationInvitationRepository::class),
            static::getService(InvitationManager::class),
            $this->clock,
            static::getService(Locker::class),
        );
        $this->commandTester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());

        parent::tearDown();
    }

    public function testExpiresPendingInvitationPastExpiry(): void
    {
        $connection = static::getService(Connection::class);
        $organization = $this->createOrgWithPendingInvitation();
        $invitation = $this->pendingInvitation($organization);

        $this->clock->sleep((InvitationManager::INVITATION_EXPIRY_DAYS + 1) * 86400);

        $this->commandTester->execute([]);
        $this->commandTester->assertCommandIsSuccessful();
        self::assertStringContainsString('Expired 1 invitation(s).', $this->commandTester->getDisplay());

        $reloaded = $this->reload($invitation);
        self::assertSame(InvitationStatus::Expired, $reloaded->status);
        self::assertNotNull($reloaded->resolvedAt);

        // Recorded by the cron with a system actor, no user behind it.
        $event = $connection->fetchAssociative(
            'SELECT actorLabel, actorUserId FROM organization_event WHERE aggregateId = :id AND type = :type',
            ['id' => $invitation->id->toBinary(), 'type' => 'user-invitation-expired'],
        );
        self::assertIsArray($event);
        self::assertSame('automation', $event['actorLabel']);
        self::assertNull($event['actorUserId']);
    }

    public function testLeavesStillValidInvitationPending(): void
    {
        $organization = $this->createOrgWithPendingInvitation();
        $invitation = $this->pendingInvitation($organization);

        // A day short of expiry.
        $this->clock->sleep((InvitationManager::INVITATION_EXPIRY_DAYS - 1) * 86400);

        $this->commandTester->execute([]);
        $this->commandTester->assertCommandIsSuccessful();
        self::assertStringContainsString('No pending invitations past expiry.', $this->commandTester->getDisplay());

        self::assertSame(InvitationStatus::Pending, $this->reload($invitation)->status);
    }

    public function testDryRunReportsWithoutChanging(): void
    {
        $organization = $this->createOrgWithPendingInvitation();
        $invitation = $this->pendingInvitation($organization);

        $this->clock->sleep((InvitationManager::INVITATION_EXPIRY_DAYS + 1) * 86400);

        $this->commandTester->execute(['--dry-run' => true]);
        $this->commandTester->assertCommandIsSuccessful();

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('DRY RUN', $output);
        self::assertStringContainsString('Would expire', $output);
        self::assertStringContainsString('Found 1 pending invitation(s) past expiry.', $output);

        self::assertSame(InvitationStatus::Pending, $this->reload($invitation)->status);
    }

    private function createOrgWithPendingInvitation(): OrganizationReadModel
    {
        $owner = $this->persistUser('owner', 'owner@example.org');
        $organization = $this->createOrg($owner, 'acme');
        $this->invitations()->invite($organization, $owner, 'alice@example.org', [$organization->ownersTeamId], null);

        return $organization;
    }

    private function reload(OrganizationInvitation $invitation): OrganizationInvitation
    {
        self::getEM()->clear();
        $reloaded = static::getService(OrganizationInvitationRepository::class)->find($invitation->id);
        self::assertNotNull($reloaded);

        return $reloaded;
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

    private function persistUser(string $username, string $email): User
    {
        $user = self::createUser($username, $email);
        $user->setTotpSecret('totp-secret');
        $this->store($user);

        return $user;
    }
}
