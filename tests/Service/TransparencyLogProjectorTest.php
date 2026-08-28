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

namespace App\Tests\Service;

use App\Audit\TransparencyLogScrubber;
use App\Audit\UserRegistrationMethod;
use App\Entity\AuditRecord;
use App\Entity\AuditRecordRepository;
use App\Entity\PackageRepository;
use App\Entity\PackageTransparencyLogQueueRepository;
use App\Entity\PackageTransparencyLogRepository;
use App\Service\TransparencyLogProjector;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\Uid\Ulid;

class TransparencyLogProjectorTest extends IntegrationTestCase
{
    public function testProjectsPackageNativeEventAndReturnsCreatedCount(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        $package = self::createPackage('svc/one', 'https://github.com/svc/one');
        $em->persist($package);
        $em->flush();
        $packageId = $package->getId();

        $created = self::getService(TransparencyLogProjector::class)->project(0);

        // The return value equals the number of rows actually written this run.
        $total = (int) $conn->fetchOne('SELECT COUNT(*) FROM package_transparency_log');
        self::assertSame($total, $created);
        self::assertSame(1, (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM package_transparency_log WHERE type = 'package_created' AND packageId = ?",
            [$packageId],
        ));
        self::assertSame('svc/one', $conn->fetchOne(
            "SELECT packageName FROM package_transparency_log WHERE type = 'package_created' AND packageId = ?",
            [$packageId],
        ));
    }

    public function testFansOutAccountEventToMaintainedPackages(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        $user = self::createUser('svcmaint', 'svcmaint@example.org');
        $em->persist($user);
        $em->flush();

        $p1 = self::createPackage('svc/one', 'https://github.com/svc/one', null, [$user]);
        $p2 = self::createPackage('svc/two', 'https://github.com/svc/two', null, [$user]);
        $em->persist($p1);
        $em->persist($p2);
        $em->flush();

        $em->getRepository(AuditRecord::class)->insert(AuditRecord::twoFactorAuthenticationDeactivated($user, $user, 'x'));

        $created = self::getService(TransparencyLogProjector::class)->project(0);

        self::assertSame(2, (int) $conn->fetchOne("SELECT COUNT(*) FROM package_transparency_log WHERE type = 'two_fa_deactivated'"));
        // return value accounts for every row written (the two package_created rows plus the fan-out)
        self::assertSame((int) $conn->fetchOne('SELECT COUNT(*) FROM package_transparency_log'), $created);

        self::assertSame(['svc/one', 'svc/two'], $conn->fetchFirstColumn(
            "SELECT packageName FROM package_transparency_log WHERE type = 'two_fa_deactivated' ORDER BY packageName",
        ));
    }

    /**
     * The failure the projection queue exists to fix. A record's ULID and datetime are both minted
     * when the AuditRecord is constructed, but the row is only committed at the end of its
     * transaction, so a record constructed early can land after a newer one was already projected.
     * It must still be projected, appended at the end of package_transparency_log.
     */
    public function testLateArrivingRecordIsProjectedAtTheTipOfTheLog(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);
        $projector = self::getService(TransparencyLogProjector::class);

        $user = self::createUser('latecomer', 'latecomer@example.org');
        $em->persist($user);
        $em->flush();

        $late = self::createPackage('svc/late', 'https://github.com/svc/late', null, [$user]);
        $em->persist($late);
        $em->flush();

        // Constructed now, so its ULID and datetime are early, but not yet committed: this stands in
        // for a record persisted inside a transaction that has not been flushed yet.
        $lateRecord = AuditRecord::maintainerAdded($late, $user, $user);

        // Meanwhile a newer event happens and is projected, moving the highest projected audit_log id
        // past it.
        $newer = self::createPackage('svc/newer', 'https://github.com/svc/newer');
        $em->persist($newer);
        $em->flush();
        $projector->project(0);

        $tipBefore = (int) $conn->fetchOne('SELECT MAX(leafIndex) FROM package_transparency_log');

        // Only now is the early record committed.
        $em->getRepository(AuditRecord::class)->insert($lateRecord);
        self::assertSame(1, $projector->project(0));

        $rows = $conn->fetchAllAssociative(
            "SELECT leafIndex, datetime FROM package_transparency_log WHERE type = 'maintainer_added'",
        );

        self::assertCount(1, $rows, 'the late record must be published, not skipped');
        // Appended at the end, because an append-only log cannot take an insertion...
        self::assertSame($tipBefore + 1, (int) $rows[0]['leafIndex']);
        // ...so it necessarily carries a datetime older than the leaf below it.
        $below = $conn->fetchOne('SELECT datetime FROM package_transparency_log WHERE leafIndex = ?', [$tipBefore]);
        self::assertLessThanOrEqual((string) $below, (string) $rows[0]['datetime']);
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM package_transparency_log_queue'));
    }

    /**
     * The late-arrival diagnostic: it reports how far back a late entry lands, which is the only real
     * evidence for whether the configured safety lag is the right size.
     */
    public function testLateArrivalIsLoggedWithHowFarBehindItLands(): void
    {
        $em = $this->getEM();
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string|\Stringable, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };

        $projector = new TransparencyLogProjector(
            self::getService(ManagerRegistry::class),
            self::getService(TransparencyLogScrubber::class),
            self::getService(AuditRecordRepository::class),
            self::getService(PackageTransparencyLogRepository::class),
            self::getService(PackageTransparencyLogQueueRepository::class),
            self::getService(PackageRepository::class),
            $logger,
        );

        $user = self::createUser('logged', 'logged@example.org');
        $em->persist($user);
        $em->flush();

        $package = self::createPackage('svc/logged', 'https://github.com/svc/logged', null, [$user]);
        $em->persist($package);
        $em->flush();

        $lateRecord = AuditRecord::maintainerAdded($package, $user, $user);

        // A ULID's timestamp has millisecond resolution, so without a gap here the two records can be
        // constructed inside the same millisecond and the reported delta is a legitimate 0.
        usleep(2000);

        $newer = self::createPackage('svc/logged-newer', 'https://github.com/svc/logged-newer');
        $em->persist($newer);
        $em->flush();
        $projector->project(0);

        $em->getRepository(AuditRecord::class)->insert($lateRecord);
        $projector->project(0);

        $warnings = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => $record['level'] === LogLevel::WARNING,
        ));

        self::assertCount(1, $warnings);
        self::assertStringContainsString('Late-arriving audit record', (string) $warnings[0]['message']);
        self::assertSame((string) $lateRecord->id, $warnings[0]['context']['auditLogId']);
        self::assertGreaterThan(0, $warnings[0]['context']['behindNewestProjectedSeconds']);
        self::assertSame(0, $warnings[0]['context']['safetyLagSeconds']);
    }

    public function testOutOfScopeQueuedRecordIsDequeuedWithoutProjecting(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        $user = self::createUser('seeded', 'seeded@example.org');
        $em->persist($user);
        $em->flush();

        // A seed naming a type we do not project is the only way to get here.
        $record = AuditRecord::userCreated($user, UserRegistrationMethod::REGISTRATION_FORM);
        $em->getRepository(AuditRecord::class)->insert($record);
        $conn->executeStatement('INSERT IGNORE INTO package_transparency_log_queue (auditLogId) VALUES (?)', [$record->id->toBinary()]);

        self::assertSame(0, self::getService(TransparencyLogProjector::class)->project(0));

        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM package_transparency_log'));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM package_transparency_log_queue'));
    }

    public function testQueuedIdWithNoAuditRowIsDropped(): void
    {
        $conn = self::getService(Connection::class);

        $conn->executeStatement('INSERT INTO package_transparency_log_queue (auditLogId) VALUES (?)', [(new Ulid())->toBinary()]);

        self::assertSame(0, self::getService(TransparencyLogProjector::class)->project(0));

        // Dropped rather than left pending: it can never become projectable, and it would otherwise
        // pin the queue-age signal used to detect a stuck projection.
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM package_transparency_log_queue'));
    }

    public function testPassThatProjectsNothingStillTerminates(): void
    {
        $conn = self::getService(Connection::class);

        // More than one batch of records, all too fresh to project. Progress has to come from paging
        // the queue, because nothing is dequeued: a loop driven by rows disappearing would spin here.
        $ids = [];
        for ($i = 0; $i < 501; $i++) {
            $ids[] = (new Ulid())->toBinary();
        }

        $conn->executeStatement(
            'INSERT INTO audit_log (id, datetime, type, attributes) VALUES '
                .implode(', ', array_fill(0, \count($ids), "(?, NOW(), 'package_created', '{}')")),
            $ids,
        );
        $conn->executeStatement(
            'INSERT INTO package_transparency_log_queue (auditLogId) VALUES '
                .implode(', ', array_fill(0, \count($ids), '(?)')),
            $ids,
        );

        self::assertSame(0, self::getService(TransparencyLogProjector::class)->project(3600));

        self::assertSame(501, (int) $conn->fetchOne('SELECT COUNT(*) FROM package_transparency_log_queue'));
    }
}
