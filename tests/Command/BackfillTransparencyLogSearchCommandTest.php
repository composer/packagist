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

use App\Command\BackfillTransparencyLogSearchCommand;
use App\Command\ProjectTransparencyLogCommand;
use App\Entity\AuditRecord;
use App\Entity\User;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Tester\CommandTester;

class BackfillTransparencyLogSearchCommandTest extends IntegrationTestCase
{
    /**
     * Entries projected before the index existed have no rows until the backfill runs.
     */
    public function testIndexesAnEntryProjectedBeforeTheIndexExisted(): void
    {
        $conn = self::getService(Connection::class);
        $user = $this->givenAProjectedMaintainerEvent();

        // like an entry projected before the index existed
        $conn->executeStatement('DELETE FROM package_transparency_log_search');
        self::assertSame(0, $this->indexedRowsFor($user));

        $tester = $this->backfill();

        self::assertStringContainsString('2 row(s) indexed', $tester->getDisplay());
        self::assertSame(2, $this->indexedRowsFor($user));
    }

    public function testReRunningIndexesNothingFurther(): void
    {
        $this->givenAProjectedMaintainerEvent();
        self::getService(Connection::class)->executeStatement('DELETE FROM package_transparency_log_search');

        $this->backfill();
        $tester = $this->backfill();

        self::assertStringContainsString('0 row(s) indexed', $tester->getDisplay());
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $user = $this->givenAProjectedMaintainerEvent();
        self::getService(Connection::class)->executeStatement('DELETE FROM package_transparency_log_search');

        $tester = $this->backfill(['--dry-run' => true]);

        self::assertStringContainsString('2 row(s) would be indexed', $tester->getDisplay());
        self::assertSame(0, $this->indexedRowsFor($user));
    }

    /**
     * Returns the user that the projected maintainer event names.
     */
    private function givenAProjectedMaintainerEvent(): User
    {
        $em = $this->getEM();

        $user = self::createUser('backfilled', 'backfilled@example.org');
        $em->persist($user);
        $em->flush();

        $package = self::createPackage('backfill/history', 'https://github.com/backfill/history', null, [$user]);
        $em->persist($package);
        $em->flush();

        // the user is both subject and actor, so this gives one row per role
        $em->getRepository(AuditRecord::class)->insert(AuditRecord::maintainerAdded($package, $user, $user));

        $tester = new CommandTester(self::getService(ProjectTransparencyLogCommand::class));
        $tester->execute(['--min-event-age-to-project' => '0']);
        $tester->assertCommandIsSuccessful();

        return $user;
    }

    private function indexedRowsFor(User $user): int
    {
        return (int) self::getService(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM package_transparency_log_search WHERE userId = ?',
            [$user->getId()],
        );
    }

    /**
     * @param array<string, bool|string> $input
     */
    private function backfill(array $input = []): CommandTester
    {
        $tester = new CommandTester(self::getService(BackfillTransparencyLogSearchCommand::class));
        $tester->execute($input);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }
}
