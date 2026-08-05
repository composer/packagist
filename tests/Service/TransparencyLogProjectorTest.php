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

use App\Entity\AuditRecord;
use App\Service\TransparencyLogProjector;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;

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
    }
}
