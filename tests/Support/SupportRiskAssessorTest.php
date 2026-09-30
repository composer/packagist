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

namespace App\Tests\Support;

use App\Audit\UserRegistrationMethod;
use App\Entity\AuditRecord;
use App\Entity\AuditRecordRepository;
use App\Entity\UserFreezeReason;
use App\Support\SupportRiskAssessor;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Types\Types;

class SupportRiskAssessorTest extends IntegrationTestCase
{
    private SupportRiskAssessor $assessor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assessor = self::getContainer()->get(SupportRiskAssessor::class);
    }

    public function testTwoFactorEnabledAtReportsTheLatestActivation(): void
    {
        $user = self::createUser('alice', 'alice@example.org');
        $this->store($user);

        $this->record(AuditRecord::twoFactorAuthenticationActivated($user, $user), self::ago('-2 years'));
        $this->record(AuditRecord::twoFactorAuthenticationDeactivated($user, $user, 'lost device'), self::ago('-8 days'));
        $enabledAt = self::ago('-7 days');
        $this->record(AuditRecord::twoFactorAuthenticationActivated($user, $user), $enabledAt);

        $this->assertEquals($enabledAt, $this->assessor->assess($user)->twoFactorEnabledAt);
    }

    public function testTwoFactorEnabledAtIsUnknownWithoutAnActivationOfItsOwn(): void
    {
        $user = self::createUser('alice', 'alice@example.org');
        $other = self::createUser('bob', 'bob@example.org');
        $this->store($user, $other);

        $this->record(AuditRecord::twoFactorAuthenticationActivated($other, $other), self::ago('-1 day'));

        $this->assertNull($this->assessor->assess($user)->twoFactorEnabledAt);
    }

    public function testRecentSecurityEventsAreScopedToTheAccountAndTheWindow(): void
    {
        $user = self::createUser('alice', 'alice@example.org');
        $other = self::createUser('bob', 'bob@example.org');
        $this->store($user, $other);

        $this->record(AuditRecord::passwordChanged($user, $user), self::ago('-'.(SupportRiskAssessor::SECURITY_EVENT_WINDOW_DAYS + 1).' days'));
        $this->record(AuditRecord::userCreated($user, UserRegistrationMethod::REGISTRATION_FORM), self::ago('-3 days'));
        $this->record(AuditRecord::passwordChanged($other, $other), self::ago('-2 days'));
        $emailChanged = $this->record(AuditRecord::emailChanged($user, $user, 'old@example.org'), self::ago('-2 days'));
        $frozen = $this->record(AuditRecord::userFrozen($user, $other, UserFreezeReason::Spam), self::ago('-1 day'));

        $events = $this->assessor->assess($user)->recentSecurityEvents;

        // newest first, and neither the out-of-window change, the other account's, nor the
        // non-security user_created event are in there
        $this->assertSame(
            [$frozen->id->toRfc4122(), $emailChanged->id->toRfc4122()],
            array_map(static fn (AuditRecord $record): string => $record->id->toRfc4122(), $events),
        );
    }

    public function testAReleasedHandleDoesNotSurfaceItsPreviousHoldersEvents(): void
    {
        $user = self::createUser('alice', 'alice@example.org');
        $other = self::createUser('bob', 'bob@example.org');
        $this->store($user, $other);

        // alice used to be called bob, so the rename indexes "bob" too — but it is alice's event,
        // and the real bob's risk profile must not pick it up
        $rename = $this->record(AuditRecord::usernameChanged($user, $user, 'bob'), self::ago('-1 day'));

        $this->assertSame([], $this->assessor->assess($other)->recentSecurityEvents);
        $this->assertSame(
            [$rename->id->toRfc4122()],
            array_map(static fn (AuditRecord $record): string => $record->id->toRfc4122(), $this->assessor->assess($user)->recentSecurityEvents),
        );
    }

    public function testHistoryWrittenUnderAFormerHandleIsNotReachedAnyMore(): void
    {
        $user = self::createUser('alice', 'alice@example.org');
        $this->store($user);

        $this->record(AuditRecord::passwordChanged($user, $user), self::ago('-1 day'));

        $user->setUsername('alicia');
        self::getEM()->flush();

        // Known cost of resolving through audit_log_search, which is keyed by name: the rename
        // itself indexes both handles and still shows up, everything older does not.
        $this->assertSame([], $this->assessor->assess($user)->recentSecurityEvents);
    }

    public function testRecentSecurityEventsAreCapped(): void
    {
        $user = self::createUser('alice', 'alice@example.org');
        $this->store($user);

        // oldest first, so the records are written in the order they would really have happened
        for ($hoursAgo = SupportRiskAssessor::MAX_SECURITY_EVENTS + 1; $hoursAgo > 0; $hoursAgo--) {
            $newest = $this->record(AuditRecord::passwordChanged($user, $user), self::ago('-'.$hoursAgo.' hours'));
        }

        $events = $this->assessor->assess($user)->recentSecurityEvents;

        $this->assertCount(SupportRiskAssessor::MAX_SECURITY_EVENTS, $events);
        $this->assertSame($newest->id->toRfc4122(), $events[0]->id->toRfc4122());
    }

    /** audit_log.datetime is a DATETIME, so keep the expectation at whole seconds too */
    private static function ago(string $modifier): \DateTimeImmutable
    {
        return new \DateTimeImmutable(new \DateTimeImmutable($modifier)->format('Y-m-d H:i:s'));
    }

    /**
     * Writes the record through the direct-insert path, which is what populates audit_log_search.
     * `datetime` is readonly, so the backdating happens on the row instead, which is what the
     * assessor reads back anyway.
     */
    private function record(AuditRecord $record, \DateTimeImmutable $at): AuditRecord
    {
        $repository = self::getEM()->getRepository(AuditRecord::class);
        self::assertInstanceOf(AuditRecordRepository::class, $repository);
        $repository->insert($record);

        self::getEM()->getConnection()->executeStatement(
            'UPDATE audit_log SET datetime = ? WHERE id = ?',
            [$at, $record->id->toBinary()],
            [Types::DATETIME_IMMUTABLE],
        );

        return $record;
    }
}
