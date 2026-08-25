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

use App\Command\ProjectTransparencyLogCommand;
use App\Command\SeedTransparencyLogQueueCommand;
use App\Entity\AuditRecord;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Tester\CommandTester;

class SeedTransparencyLogQueueCommandTest extends IntegrationTestCase
{
    /**
     * The command's whole purpose: a package-native audit record with no queue row is never projected
     * on its own, and only an explicit seed gives it one.
     */
    public function testSeedsAPackageNativeRecordThatHasNoQueueRow(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        $user = self::createUser('seeded', 'seeded@example.org');
        $em->persist($user);
        $em->flush();

        $package = self::createPackage('seed/history', 'https://github.com/seed/history', null, [$user]);
        $em->persist($package);
        $em->flush();

        // Stands in for a record written before the queue existed.
        $historical = AuditRecord::maintainerAdded($package, $user, $user);
        $em->getRepository(AuditRecord::class)->insert($historical);
        $conn->executeStatement('DELETE FROM package_transparency_log_queue WHERE auditLogId = ?', [$historical->id->toBinary()]);

        $this->runProjector();
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM package_transparency_log WHERE type = 'maintainer_added'"));

        $this->seed();

        self::assertSame(1, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM package_transparency_log_queue WHERE auditLogId = ?',
            [$historical->id->toBinary()],
        ));

        $this->runProjector();
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM package_transparency_log WHERE type = 'maintainer_added'"));
    }

    /**
     * Account events fan out to whoever maintains the package at projection time, so seeding an old one
     * would publish it against today's maintainer set, and that cannot be retracted. It must therefore
     * be skipped wherever it sits relative to what has already been projected.
     */
    public function testAccountEventsAreNeverSeeded(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        $user = self::createUser('nohistory', 'nohistory@example.org');
        $em->persist($user);
        $em->flush();

        $package = self::createPackage('seed/account', 'https://github.com/seed/account', null, [$user]);
        $em->persist($package);
        $em->flush();

        $accountEvent = AuditRecord::twoFactorAuthenticationDeactivated($user, $user, 'x');
        $em->getRepository(AuditRecord::class)->insert($accountEvent);
        $conn->executeStatement('DELETE FROM package_transparency_log_queue WHERE auditLogId = ?', [$accountEvent->id->toBinary()]);

        $this->seed();

        self::assertSame(0, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM package_transparency_log_queue WHERE auditLogId = ?',
            [$accountEvent->id->toBinary()],
        ));
    }

    public function testAlreadyProjectedRecordsAreNotSeededAgain(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        $package = self::createPackage('seed/done', 'https://github.com/seed/done');
        $em->persist($package);
        $em->flush();

        $this->runProjector();
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM package_transparency_log'));

        $this->seed();

        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM package_transparency_log_queue'));
    }

    public function testAlreadyQueuedRecordsAreNotCounted(): void
    {
        $em = $this->getEM();

        // Written normally, so it already has a queue row and is waiting to be projected.
        $package = self::createPackage('seed/queued', 'https://github.com/seed/queued');
        $em->persist($package);
        $em->flush();

        $tester = $this->seed(['--dry-run' => true]);

        self::assertStringContainsString('0 record(s) would be enqueued', $tester->getDisplay());
    }

    public function testDryRunWritesNothing(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        $user = self::createUser('dryrun', 'dryrun@example.org');
        $em->persist($user);
        $em->flush();

        $package = self::createPackage('seed/dryrun', 'https://github.com/seed/dryrun', null, [$user]);
        $em->persist($package);
        $em->flush();

        $historical = AuditRecord::maintainerAdded($package, $user, $user);
        $em->getRepository(AuditRecord::class)->insert($historical);
        $conn->executeStatement('DELETE FROM package_transparency_log_queue WHERE auditLogId = ?', [$historical->id->toBinary()]);

        $tester = $this->seed(['--dry-run' => true]);

        self::assertStringContainsString('1 record(s) would be enqueued', $tester->getDisplay());
        self::assertSame(0, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM package_transparency_log_queue WHERE auditLogId = ?',
            [$historical->id->toBinary()],
        ));
    }

    /**
     * @param array<string, bool> $input
     */
    private function seed(array $input = []): CommandTester
    {
        $tester = new CommandTester(self::getService(SeedTransparencyLogQueueCommand::class));
        $tester->execute($input);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    private function runProjector(): void
    {
        $tester = new CommandTester(self::getService(ProjectTransparencyLogCommand::class));
        $tester->execute(['--min-event-age-to-project' => '0']);
        $tester->assertCommandIsSuccessful();
    }
}
