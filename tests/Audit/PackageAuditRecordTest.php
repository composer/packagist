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

namespace App\Tests\Controller;

use App\Audit\AuditRecordType;
use App\Entity\Package;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PackageAuditRecordTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        static::getContainer()->get(Connection::class)->beginTransaction();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(Connection::class)->rollBack();

        parent::tearDown();
    }

    public function testPackageChangesGetRecorded(): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $package = new Package();
        $package->setRepository('https://github.com/composer/composer');

        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(1, $logs);
        self::assertSame(AuditRecordType::PackageCreated->value, $logs[0]['type']);

        $package->setRepository('https://github.com/composer/packagist');
        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(2, $logs);
        self::assertSame(AuditRecordType::CanonicalUrlChange->value, $logs[0]['type']);
        self::assertSame('{"name": "composer/composer", "actor": "unknown", "repository_to": "https://github.com/composer/packagist", "repository_from": "https://github.com/composer/composer"}', $logs[0]['attributes']);

        $em->remove($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(3, $logs);
        self::assertSame(AuditRecordType::PackageDeleted->value, $logs[0]['type']);
    }
}
