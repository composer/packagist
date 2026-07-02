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

use App\Command\PopulateAuditLogSearchCommand;
use App\Entity\AuditRecord;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Tester\CommandTester;

class PopulateAuditLogSearchCommandTest extends IntegrationTestCase
{
    public function testBackfillReindexesExistingRecords(): void
    {
        $em = $this->getEM();
        $conn = self::getContainer()->get(Connection::class);

        // Two records get indexed by the live write paths as they are created...
        $user = self::createUser('naderman', 'nader@example.org');
        self::store($user);

        $package = self::createPackage('acme/widget', 'https://github.com/acme/widget');
        new \ReflectionProperty($package, 'id')->setValue($package, 100);
        $em->getRepository(AuditRecord::class)->insert(AuditRecord::packageDeleted($package, null));

        // ...now simulate the pre-index world by dropping what those writes indexed.
        $conn->executeStatement('DELETE FROM audit_log_search');
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM audit_log_search'));

        $command = new PopulateAuditLogSearchCommand(self::getContainer()->get(ManagerRegistry::class));
        $tester = new CommandTester($command);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        /** @var list<string> $names */
        $names = $conn->fetchFirstColumn("SELECT CONCAT(type, ':', name) FROM audit_log_search");
        self::assertContains('user:naderman', $names);
        self::assertContains('package:acme/widget', $names);

        // progress prints the last processed datetime so a died run can resume via --from-date
        self::assertStringContainsString('last datetime:', $tester->getDisplay());
    }

    public function testFromDateLimitsWhichRecordsAreReindexed(): void
    {
        $conn = self::getContainer()->get(Connection::class);

        $user = self::createUser('naderman', 'nader@example.org');
        self::store($user);

        $conn->executeStatement('DELETE FROM audit_log_search');

        $command = new PopulateAuditLogSearchCommand(self::getContainer()->get(ManagerRegistry::class));
        $tester = new CommandTester($command);

        // A future cut-off matches nothing, so nothing gets reindexed
        $tester->execute(['--from-date' => '2999-01-01']);
        $tester->assertCommandIsSuccessful();
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM audit_log_search'));

        // A past cut-off matches everything and reindexes it
        $tester->execute(['--from-date' => '2000-01-01']);
        $tester->assertCommandIsSuccessful();
        /** @var list<string> $names */
        $names = $conn->fetchFirstColumn("SELECT CONCAT(type, ':', name) FROM audit_log_search");
        self::assertContains('user:naderman', $names);
    }

    public function testInvalidFromDateFails(): void
    {
        $command = new PopulateAuditLogSearchCommand(self::getContainer()->get(ManagerRegistry::class));
        $tester = new CommandTester($command);

        $tester->execute(['--from-date' => 'not-a-date']);

        self::assertSame(\Symfony\Component\Console\Command\Command::INVALID, $tester->getStatusCode());
    }
}
