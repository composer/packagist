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
use App\Entity\AuditRecord;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Tester\CommandTester;

class ProjectTransparencyLogCommandTest extends IntegrationTestCase
{
    public function testProjectsPackageEventsInOrderWithGaplessLeafIndexAndSkipsOutOfScope(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        // Package creations produce in-scope audit rows in ULID (creation) order.
        $first = self::createPackage('acme/one', 'https://github.com/acme/one');
        $em->persist($first);
        $em->flush();
        $firstId = $first->getId();

        $second = self::createPackage('acme/two', 'https://github.com/acme/two');
        $em->persist($second);
        $em->flush();

        // Out-of-scope: a user creation must not be projected.
        self::store(self::createUser('bob', 'bob@example.org'));

        $this->runProjector('0');

        $rows = $conn->fetchAllAssociative('SELECT type, leafIndex, packageId FROM package_transparency_log ORDER BY leafIndex ASC');

        self::assertCount(2, $rows);
        self::assertSame(0, (int) $rows[0]['leafIndex']);
        self::assertSame(1, (int) $rows[1]['leafIndex']);
        self::assertSame('package_created', $rows[0]['type']);
        self::assertSame('package_created', $rows[1]['type']);
        // leaf order follows ULID (chronological) order: the first-created package is leaf 0
        self::assertSame($firstId, (int) $rows[0]['packageId']);
    }

    public function testFreshRecordsAreExcludedBySafetyLagUntilTheyAge(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        $package = self::createPackage('acme/lag', 'https://github.com/acme/lag');
        $em->persist($package);
        $em->flush();

        // Default (5-minute) window: the just-created record is too fresh, nothing is projected.
        $this->runProjector();
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM package_transparency_log'));

        // With no lag it is old enough and gets projected.
        $this->runProjector('0');
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM package_transparency_log'));
    }

    public function testReRunIsIdempotentAndKeepsCursor(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        $package = self::createPackage('acme/idem', 'https://github.com/acme/idem');
        $em->persist($package);
        $em->flush();

        $this->runProjector('0');
        $this->runProjector('0');

        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM package_transparency_log'));
        self::assertSame(0, (int) $conn->fetchOne('SELECT MAX(leafIndex) FROM package_transparency_log'));
    }

    public function testProjectedAttributesAreScrubbed(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        $package = self::createPackage('acme/scrub', 'https://github.com/acme/scrub');
        $em->persist($package);
        $em->flush();

        // Deletion carries a public reason and an admin-only internal reason (with PII).
        $package->setAuditDeletionReason('public takedown notice');
        $package->setAuditDeletionInternalReason('internal: reporter jane@example.com, ticket #42');
        $em->remove($package);
        $em->flush();

        $this->runProjector('0');

        /** @var string|false $attributesJson */
        $attributesJson = $conn->fetchOne("SELECT attributes FROM package_transparency_log WHERE type = 'package_deleted'");
        self::assertIsString($attributesJson);
        $attributes = json_decode($attributesJson, true);

        self::assertSame('public takedown notice', $attributes['reason']);
        self::assertArrayNotHasKey('internalReason', $attributes);
    }

    public function testNonNumericMinAgeIsRejected(): void
    {
        $tester = new CommandTester(self::getService(ProjectTransparencyLogCommand::class));
        $tester->execute(['--min-event-age-to-project' => '5 minutes']);

        self::assertSame(\Symfony\Component\Console\Command\Command::INVALID, $tester->getStatusCode());
    }

    public function testAccountEventFansOutToDirectMaintainedPackages(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        $user = self::createUser('maint', 'maint@example.org');
        $em->persist($user);
        $em->flush();

        $p1 = self::createPackage('acme/one', 'https://github.com/acme/one', null, [$user]);
        $p2 = self::createPackage('acme/two', 'https://github.com/acme/two', null, [$user]);
        $em->persist($p1);
        $em->persist($p2);
        $em->flush();
        $p1Id = $p1->getId();
        $p2Id = $p2->getId();

        // A 2FA-off event on the user (no package of its own) should fan out to both maintained packages.
        $em->getRepository(AuditRecord::class)->insert(AuditRecord::twoFactorAuthenticationDeactivated($user, $user, 'reset by user'));

        $this->runProjector('0');

        $rows = $conn->fetchAllAssociative(
            "SELECT packageId, leafIndex, sourceAuditLogId FROM package_transparency_log WHERE type = 'two_fa_deactivated' ORDER BY packageId ASC",
        );

        self::assertCount(2, $rows);
        self::assertSame([$p1Id, $p2Id], [(int) $rows[0]['packageId'], (int) $rows[1]['packageId']]);
        // both entries came from the one source event...
        self::assertSame($rows[0]['sourceAuditLogId'], $rows[1]['sourceAuditLogId']);
        // ...but each got its own leaf index
        self::assertNotSame((int) $rows[0]['leafIndex'], (int) $rows[1]['leafIndex']);

        // Re-running projects nothing new (dedupe on (sourceAuditLogId, packageId)).
        $this->runProjector('0');
        self::assertSame(2, (int) $conn->fetchOne("SELECT COUNT(*) FROM package_transparency_log WHERE type = 'two_fa_deactivated'"));
    }

    public function testAccountEventFansOutToOrgOwnedPackages(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        $user = self::createUser('member', 'member@example.org');
        $em->persist($user);
        $em->flush();

        // The user is a member of an org that owns a package (ownership = package.vendor === org.slug),
        // but is NOT a direct maintainer of it.
        $org = self::createOrganization('acmeorg', 'Acme Org');
        self::store($org, ...self::createOwnerMembership($org, $user));

        $pkg = self::createPackage('acmeorg/lib', 'https://github.com/acmeorg/lib');
        $em->persist($pkg);
        $em->flush();
        $pkgId = $pkg->getId();

        $em->getRepository(AuditRecord::class)->insert(AuditRecord::twoFactorAuthenticationDeactivated($user, $user, 'x'));

        $this->runProjector('0');

        $rows = $conn->fetchAllAssociative("SELECT packageId FROM package_transparency_log WHERE type = 'two_fa_deactivated'");
        self::assertCount(1, $rows);
        self::assertSame($pkgId, (int) $rows[0]['packageId']);
    }

    public function testAccountEventForUserWithNoPackagesProducesNothing(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        $user = self::createUser('lonely', 'lonely@example.org');
        $em->persist($user);
        $em->flush();

        $em->getRepository(AuditRecord::class)->insert(AuditRecord::twoFactorAuthenticationDeactivated($user, $user, 'x'));

        $this->runProjector('0');

        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM package_transparency_log WHERE type = 'two_fa_deactivated'"));
    }

    private function runProjector(?string $minAge = null): CommandTester
    {
        $command = self::getService(ProjectTransparencyLogCommand::class);
        $tester = new CommandTester($command);
        $tester->execute($minAge !== null ? ['--min-event-age-to-project' => $minAge] : []);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }
}
