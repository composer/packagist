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

use App\Audit\AbandonmentReason;
use App\Audit\AuditRecordType;
use App\Entity\Package;
use App\Event\PackageAbandonedEvent;
use App\Event\PackageUnabandonedEvent;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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
        self::assertSame(AuditRecordType::CanonicalUrlChanged->value, $logs[0]['type']);
        self::assertSame('{"name": "composer/composer", "actor": "unknown", "repository_to": "https://github.com/composer/packagist", "repository_from": "https://github.com/composer/composer"}', $logs[0]['attributes']);

        $em->remove($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(3, $logs);
        self::assertSame(AuditRecordType::PackageDeleted->value, $logs[0]['type']);
    }

    public function testPackageAbandonmentGetRecorded(): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $eventDispatcher = $container->get(EventDispatcherInterface::class);

        $package = new Package();
        $package->setName('test/package');
        $package->setRepository('https://github.com/test/package');

        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(1, $logs);
        self::assertSame(AuditRecordType::PackageCreated->value, $logs[0]['type']);

        // Test abandonment with replacement package
        $package->setAbandoned(true);
        $package->setReplacementPackage('test/replacement');
        $eventDispatcher->dispatch(new PackageAbandonedEvent($package, AbandonmentReason::Unknown));

        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(2, $logs);
        self::assertSame(AuditRecordType::PackageAbandoned->value, $logs[0]['type']);
        $attributes = json_decode($logs[0]['attributes'], true);
        self::assertSame('test/package', $attributes['name']);
        self::assertSame('https://github.com/test/package', $attributes['repository']);
        self::assertSame('test/replacement', $attributes['replacement_package']);
        self::assertSame('automation', $attributes['actor']);
        // When abandoned directly via entity setAbandoned, reason defaults to 'unknown'
        self::assertArrayHasKey('reason', $attributes);
        self::assertSame('unknown', $attributes['reason']);

        // Test unabandonment
        $package->setAbandoned(false);
        $package->setReplacementPackage(null);
        $eventDispatcher->dispatch(new PackageUnabandonedEvent($package, AbandonmentReason::Unknown));
        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(3, $logs);
        self::assertSame(AuditRecordType::PackageUnabandoned->value, $logs[0]['type']);
        $attributes = json_decode($logs[0]['attributes'], true);
        self::assertSame('test/package', $attributes['name']);
        self::assertSame('https://github.com/test/package', $attributes['repository']);
        self::assertSame('automation', $attributes['actor']);
    }

    public function testPackageAbandonmentWithoutReplacementGetRecorded(): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $eventDispatcher = $container->get(EventDispatcherInterface::class);

        $package = new Package();
        $package->setName('test/package2');
        $package->setRepository('https://github.com/test/package2');

        $em->persist($package);
        $em->flush();

        // Test abandonment without replacement package
        $package->setAbandoned(true);
        $em->persist($package);
        $eventDispatcher->dispatch(new PackageAbandonedEvent($package, AbandonmentReason::Unknown));
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log WHERE type = ? ORDER BY id DESC', [AuditRecordType::PackageAbandoned->value]);
        self::assertCount(1, $logs);
        self::assertSame(AuditRecordType::PackageAbandoned->value, $logs[0]['type']);
        $attributes = json_decode($logs[0]['attributes'], true);
        self::assertSame('test/package2', $attributes['name']);
        self::assertNull($attributes['replacement_package']);
        self::assertArrayHasKey('reason', $attributes);
        self::assertSame('unknown', $attributes['reason']);

        // Test unabandonment when there was no replacement package
        $package->setAbandoned(false);
        $em->persist($package);
        $eventDispatcher->dispatch(new PackageUnabandonedEvent($package, AbandonmentReason::Unknown));
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log WHERE type = ? ORDER BY id DESC', [AuditRecordType::PackageUnabandoned->value]);
        self::assertCount(1, $logs);
        self::assertSame(AuditRecordType::PackageUnabandoned->value, $logs[0]['type']);
        $attributes = json_decode($logs[0]['attributes'], true);
        self::assertSame('test/package2', $attributes['name']);
    }
}
