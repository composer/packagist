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

use App\Organization\Domain\Email;
use App\Organization\Domain\Event\UserInvitationAccepted;
use App\Organization\Domain\Event\UserInvitationDeclined;
use App\Organization\Domain\Event\UserInvitationExpired;
use App\Organization\Domain\Event\UserInvitationSent;
use App\Organization\Domain\Exception\EmailMismatchException;
use App\Organization\Domain\Exception\NoPendingInvitationException;
use App\Organization\Domain\Exception\NoTeamSpecifiedException;
use App\Organization\Domain\Exception\PolicyNotMetException;
use App\Organization\Domain\Exception\TeamNotFoundException;
use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

class InvitationAggregateTest extends TestCase
{
    public function testSendRequiresAtLeastOneTeam(): void
    {
        $this->expectException(NoTeamSpecifiedException::class);

        Invitation::send(new Ulid(), new Ulid(), new Email('a@example.org'), [], 'hash', $this->future());
    }

    public function testSendRecordsPendingInvitation(): void
    {
        $orgId = new Ulid();
        $teamId = new Ulid();
        $invitation = Invitation::send(new Ulid(), $orgId, new Email('Alice@example.org'), [$teamId], 'hash', $this->future());

        $events = $invitation->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(UserInvitationSent::class, $events[0]);
        self::assertSame('alice@example.org', $events[0]->emailCanonical);
        self::assertSame(InvitationStatus::Pending, $invitation->status());
    }

    public function testAcceptRecordsAcceptanceForMatchingEmail(): void
    {
        $teamId = new Ulid();
        $invitation = $this->pendingInvitation([$teamId]);

        $invitation->accept(42, 'alice@example.org', [$teamId], false, false, $this->now());

        $events = $invitation->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(UserInvitationAccepted::class, $events[0]);
        self::assertSame(42, $events[0]->userId);
        self::assertSame(InvitationStatus::Accepted, $invitation->status());
    }

    public function testAcceptRejectsMismatchedEmail(): void
    {
        $teamId = new Ulid();
        $invitation = $this->pendingInvitation([$teamId]);

        $this->expectException(EmailMismatchException::class);
        $invitation->accept(42, 'bob@example.org', [$teamId], false, true, $this->now());
    }

    public function testAcceptToOwnersRequiresTwoFactor(): void
    {
        $teamId = new Ulid();
        $invitation = $this->pendingInvitation([$teamId]);

        $this->expectException(PolicyNotMetException::class);
        // ownersAmongTeams = true, hasTwoFactor = false
        $invitation->accept(42, 'alice@example.org', [$teamId], true, false, $this->now());
    }

    public function testAcceptFailsWhenNoTargetTeamRemains(): void
    {
        $invitation = $this->pendingInvitation([new Ulid()]);

        $this->expectException(TeamNotFoundException::class);
        $invitation->accept(42, 'alice@example.org', [], false, true, $this->now());
    }

    public function testAcceptFailsForExpiredInvitation(): void
    {
        $teamId = new Ulid();
        $invitation = Invitation::send(new Ulid(), new Ulid(), new Email('alice@example.org'), [$teamId], 'hash', $this->past());
        $invitation->pullPendingEvents();

        $this->expectException(NoPendingInvitationException::class);
        $invitation->accept(42, 'alice@example.org', [$teamId], false, true, $this->now());
    }

    public function testDeclineRecordsForMatchingEmail(): void
    {
        $invitation = $this->pendingInvitation([new Ulid()]);

        $invitation->decline('alice@example.org', $this->now());

        $events = $invitation->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(UserInvitationDeclined::class, $events[0]);
        self::assertSame(InvitationStatus::Declined, $invitation->status());
    }

    public function testDeclineIsNoOpWhenExpired(): void
    {
        $invitation = Invitation::send(new Ulid(), new Ulid(), new Email('alice@example.org'), [new Ulid()], 'hash', $this->past());
        $invitation->pullPendingEvents();

        $invitation->decline('alice@example.org', $this->now());

        self::assertSame([], $invitation->pullPendingEvents());
        self::assertSame(InvitationStatus::Pending, $invitation->status());
    }

    public function testRevokeIsNoOpOnceResolved(): void
    {
        $teamId = new Ulid();
        $invitation = $this->pendingInvitation([$teamId]);
        $invitation->accept(42, 'alice@example.org', [$teamId], false, true, $this->now());
        $invitation->pullPendingEvents();

        $invitation->revoke($this->now());

        self::assertSame([], $invitation->pullPendingEvents());
    }

    public function testRevokeIsNoOpWhenExpired(): void
    {
        $invitation = Invitation::send(new Ulid(), new Ulid(), new Email('alice@example.org'), [new Ulid()], 'hash', $this->past());
        $invitation->pullPendingEvents();

        $invitation->revoke($this->now());

        self::assertSame([], $invitation->pullPendingEvents());
        self::assertSame(InvitationStatus::Pending, $invitation->status());
    }

    public function testResendCannotReviveExpiredInvitation(): void
    {
        $invitation = Invitation::send(new Ulid(), new Ulid(), new Email('alice@example.org'), [new Ulid()], 'hash', $this->past());
        $invitation->pullPendingEvents();

        $this->expectException(NoPendingInvitationException::class);
        $invitation->resend('newhash', $this->future(), $this->now());
    }

    public function testMarkExpiredRecordsOnceWhenDue(): void
    {
        $invitation = Invitation::send(new Ulid(), new Ulid(), new Email('alice@example.org'), [new Ulid()], 'hash', $this->past());
        $invitation->pullPendingEvents();

        $invitation->markExpired($this->now());
        $events = $invitation->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(UserInvitationExpired::class, $events[0]);

        // Already expired: a second sweep is a no-op.
        $invitation->markExpired($this->now());
        self::assertSame([], $invitation->pullPendingEvents());
    }

    /**
     * @param list<Ulid> $teamIds
     */
    private function pendingInvitation(array $teamIds): Invitation
    {
        $invitation = Invitation::send(new Ulid(), new Ulid(), new Email('alice@example.org'), $teamIds, 'hash', $this->future());
        $invitation->pullPendingEvents();

        return $invitation;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-16 12:00:00');
    }

    private function future(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-23 12:00:00');
    }

    private function past(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-09 12:00:00');
    }
}
