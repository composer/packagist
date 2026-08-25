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

namespace App\Tests\Entity;

use App\Audit\UserRegistrationMethod;
use App\Entity\AuditRecord;
use App\Entity\AuditRecordRepository;
use App\Entity\PackageTransparencyLogQueueRepository;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;

/**
 * The queue is only as good as its coverage of the write paths: a record that reaches audit_log
 * without a queue row can never be projected, which is the failure the queue exists to remove. These
 * tests pin both paths ({@see AuditRecordRepository::insert()} and the postPersist listener).
 */
class PackageTransparencyLogQueueRepositoryTest extends IntegrationTestCase
{
    public function testOrmPersistedAuditRecordIsEnqueued(): void
    {
        $em = $this->getEM();

        $user = self::createUser('ormpath', 'ormpath@example.org');
        $em->persist($user);
        $em->flush();

        $package = self::createPackage('queue/orm', 'https://github.com/queue/orm');
        $em->persist($package);
        $em->flush();

        $record = AuditRecord::maintainerAdded($package, $user, $user);
        $em->persist($record);
        $em->flush();

        self::assertTrue($this->isQueued($record));
    }

    public function testDirectlyInsertedAuditRecordIsEnqueued(): void
    {
        $em = $this->getEM();

        $user = self::createUser('rawpath', 'rawpath@example.org');
        $em->persist($user);
        $em->flush();

        $record = AuditRecord::twoFactorAuthenticationDeactivated($user, $user, 'x');
        $em->getRepository(AuditRecord::class)->insert($record);

        self::assertTrue($this->isQueued($record));
    }

    public function testOutOfScopeAuditRecordIsNotEnqueued(): void
    {
        $em = $this->getEM();

        $user = self::createUser('outofscope', 'outofscope@example.org');
        $em->persist($user);
        $em->flush();

        // Only projectable types are enqueued, so the queue holds exactly what is pending.
        $record = AuditRecord::userCreated($user, UserRegistrationMethod::REGISTRATION_FORM);
        $em->getRepository(AuditRecord::class)->insert($record);

        self::assertFalse($this->isQueued($record));
    }

    public function testEnqueueIsIdempotent(): void
    {
        $em = $this->getEM();
        $queue = self::getService(PackageTransparencyLogQueueRepository::class);

        $user = self::createUser('twice', 'twice@example.org');
        $em->persist($user);
        $em->flush();

        $record = AuditRecord::twoFactorAuthenticationDeactivated($user, $user, 'x');
        $em->getRepository(AuditRecord::class)->insert($record);
        $queue->enqueue($record);

        self::assertSame(1, (int) self::getService(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM package_transparency_log_queue WHERE auditLogId = ?',
            [$record->id->toBinary()],
        ));
    }

    /**
     * The property that makes the queue an outbox rather than a second copy: the queue row and the
     * audit row share a transaction, so a record can never become visible without being pending.
     */
    public function testAuditRecordAndQueueRowRollBackTogether(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        $user = self::createUser('rollback', 'rollback@example.org');
        $em->persist($user);
        $em->flush();

        // Nested inside the per-test transaction, so DBAL makes this a savepoint.
        $conn->beginTransaction();
        $record = AuditRecord::twoFactorAuthenticationDeactivated($user, $user, 'x');
        $em->persist($record);
        $em->flush();
        self::assertTrue($this->isQueued($record));
        $conn->rollBack();
        $em->clear();

        self::assertFalse($this->isQueued($record), 'the queue row must not outlive the audit record');
        self::assertSame(0, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE id = ?',
            [$record->id->toBinary()],
        ));
    }

    private function isQueued(AuditRecord $record): bool
    {
        return (int) self::getService(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM package_transparency_log_queue WHERE auditLogId = ?',
            [$record->id->toBinary()],
        ) === 1;
    }
}
