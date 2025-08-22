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
use App\Entity\Version;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class VersionAuditRecordTest extends KernelTestCase
{
    public function setUp(): void
    {
        self::bootKernel();
        static::getContainer()->get(Connection::class)->beginTransaction();

        parent::setUp();
    }

    public function tearDown(): void
    {
        static::getContainer()->get(Connection::class)->rollBack();

        parent::tearDown();
    }

    public function testVersionChangesGetRecorded(): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $package = new Package();
        $package->setRepository('https://github.com/composer/composer');

        $version = new Version();
        $version->setPackage($package);
        $version->setName($package->getName());
        $version->setVersion('1.0.0');
        $version->setNormalizedVersion('1.0.0.0');
        $version->setDevelopment(false);
        $version->setLicense([]);
        $version->setAutoload([]);
        $version->setDist(['reference' => 'old-dist-ref', 'type' => 'zip', 'url' => 'https://example.org/dist.zip']);

        $em->persist($package);
        $em->persist($version);
        $em->flush();

        $version->setDist(['reference' => 'new-dist-ref', 'type' => 'zip', 'url' => 'https://example.org/dist.zip']);
        $version->setSource(['reference' => 'new-source-ref', 'type' => 'git', 'url' => 'git://example.org/dist.zip']);
        $em->persist($version);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(2, $logs); // package creation + version reference change
        self::assertSame(AuditRecordType::VersionReferenceChange->value, $logs[0]['type']);
        self::assertSame('{"name": "composer/composer", "dist_to": "new-dist-ref", "version": "1.0.0", "dist_from": "old-dist-ref", "source_to": "new-source-ref", "source_from": null}', $logs[0]['attributes']);

        // verify that unrelated changes do not create new audit logs
        $version->setLicense(['MIT']);
        $em->persist($version);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(2, $logs); // package creation + version reference change

        // verify that changing dist only without ref change does not create new audit log and does not crash
        $version->setDist(['reference' => 'new-dist-ref', 'type' => 'zip2', 'url' => 'https://example.org/dist.zip2']);
        $em->persist($version);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(2, $logs); // package creation + version reference change

        // verify that only reference changes triggers a new audit log
        $version->setDist(['reference' => 'new-dist-ref', 'type' => 'zip3', 'url' => 'https://example.org/dist.zip2']);
        $version->setSource(['reference' => 'new-source-ref', 'type' => 'git2', 'url' => 'git://example.org/dist.zip2']);
        $em->persist($version);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(2, $logs);

        $em->remove($version);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(3, $logs);
        self::assertSame(AuditRecordType::VersionDeleted->value, $logs[0]['type']);
    }
}
