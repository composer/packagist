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
use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\RequireLink;
use App\Entity\Version;
use App\Event\VersionReferenceChangedEvent;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class VersionAuditRecordTest extends KernelTestCase
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

    public function testVersionCreationGetsRecorded(): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $version = $this->createPackageAndVersion();

        $log = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::VersionCreated,
            'packageId' => $version->getPackage()->getId(),
        ]);

        self::assertNotNull($log, 'No audit record created for new version');
        self::assertSame('composer', $log->vendor);
        $attributes = $log->attributes;
        self::assertSame('composer/composer', $attributes['name']);
        self::assertSame('automation', $attributes['actor']);
        self::assertSame('1.0.0', $attributes['version']);
        self::assertSame('dist-ref', $attributes['metadata']['dist']['reference']);
        self::assertSame('source-ref', $attributes['metadata']['source']['reference']);
        self::assertSame('^1.5.0', $attributes['metadata']['require']['composer/ca-bundle']);
    }

    public function testVersionChangesGetRecorded(): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $eventDispatcher = $container->get(EventDispatcherInterface::class);

        $version = $this->createPackageAndVersion();

        $originalMetadata = $version->toV2Array([]);
        $version->setDist(['reference' => 'new-dist-ref', 'type' => 'zip', 'url' => 'https://example.org/dist.zip']);
        $version->setSource(['reference' => 'new-source-ref', 'type' => 'git', 'url' => 'git://example.org/dist.zip']);

        $changeEvent = new VersionReferenceChangedEvent($version, $originalMetadata, 'source-ref', 'dist-ref', $version->getSource()['reference'] ?? null, $version->getDist()['type'] ?? null);
        $eventDispatcher->dispatch($changeEvent);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(3, $logs); // package creation + version creation + version reference change
        $log = $logs[0];
        $attributes = json_decode($log['attributes'] ?? '{}', true) ?? [];
        self::assertSame(AuditRecordType::VersionReferenceChanged->value, $log['type']);
        self::assertSame('composer/composer', $attributes['name']);
        self::assertSame('1.0.0', $attributes['version']);
        self::assertSame('dist-ref', $attributes['dist_from']);
        self::assertSame('new-dist-ref', $attributes['dist_to']);
        self::assertSame('source-ref', $attributes['source_from']);
        self::assertSame('new-source-ref', $attributes['source_to']);
        self::assertSame('^1.5.0', $attributes['metadata']['require']['composer/ca-bundle']);

        // verify that dev versions with no changes to metadata don't create an audit record
        $originalMetadata = $version->toV2Array([]);
        $version->setDist(['reference' => 'another-dist-ref', 'type' => 'zip', 'url' => 'https://example.org/dist.zip']);
        $version->setDevelopment(true);
        $changeEvent = new VersionReferenceChangedEvent($version, $originalMetadata, 'source-ref', 'dist-ref', $version->getSource()['reference'] ?? null, $version->getDist()['type'] ?? null);
        $eventDispatcher->dispatch($changeEvent);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(3, $logs); // package creation + version creation + version reference change

        // verify that dev versions with changes to metadata create an audit record
        $originalMetadata = $version->toV2Array([]);
        $link = new RequireLink();
        $link->setVersion($version);
        $link->setPackageVersion('^1.6.0');
        $link->setPackageName('composer/ca-bundle');
        $em->persist($link);
        $version->setDist(['reference' => 'a-dist-ref-with-metadata-changes', 'type' => 'zip', 'url' => 'https://d.io/dist.zip']);
        $version->getRequire()->clear();
        $version->addRequireLink($link);
        $changeEvent = new VersionReferenceChangedEvent($version, $originalMetadata, 'source-ref', 'another-dist-ref', $version->getSource()['reference'] ?? null, $version->getDist()['type'] ?? null);
        $eventDispatcher->dispatch($changeEvent);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(4, $logs); // package creation + version creation + version reference change x 2
        $log = $logs[0];
        $attributes = json_decode($log['attributes'] ?? '{}', true) ?? [];
        self::assertSame(AuditRecordType::VersionReferenceChanged->value, $log['type']);
        self::assertSame('another-dist-ref', $attributes['dist_from']);
        self::assertSame('a-dist-ref-with-metadata-changes', $attributes['dist_to']);
        self::assertSame('^1.6.0', $attributes['metadata']['require']['composer/ca-bundle']);

/*        // verify that unrelated changes do not create new audit logs
        $version->setLicense(['MIT']);
        $em->persist($version);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(3, $logs); // package creation + version creation + version reference change

        // verify that changing dist only without ref change does not create new audit log and does not crash
        $version->setDist(['reference' => 'new-dist-ref', 'type' => 'zip2', 'url' => 'https://example.org/dist.zip2']);
        $em->persist($version);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(3, $logs); // package creation + version creation + version reference change

        // verify that only reference changes triggers a new audit log
        $version->setDist(['reference' => 'new-dist-ref', 'type' => 'zip3', 'url' => 'https://example.org/dist.zip2']);
        $version->setSource(['reference' => 'new-source-ref', 'type' => 'git2', 'url' => 'git://example.org/dist.zip2']);
        $em->persist($version);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(3, $logs);*/
    }

    private function createPackageAndVersion(): Version
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $package = new Package();
        $package->setName('composer/composer');
        $package->setRepository('https://github.com/composer/composer');

        $version = new Version();
        $version->setPackage($package);
        $version->setName($package->getName());
        $version->setVersion('1.0.0');
        $version->setNormalizedVersion('1.0.0.0');
        $version->setDevelopment(false);
        $version->setLicense([]);
        $version->setAutoload([]);
        $version->setDist(['reference' => 'dist-ref', 'type' => 'zip', 'url' => 'https://example.org/dist.zip']);
        $version->setSource(['reference' => 'source-ref', 'type' => 'git', 'url' => 'https://example.org/dist.git']);

        $link = new RequireLink();
        $link->setVersion($version);
        $link->setPackageVersion('^1.5.0');
        $link->setPackageName('composer/ca-bundle');
        $version->addRequireLink($link);

        $em->persist($link);
        $em->persist($package);
        $em->persist($version);
        $em->flush();

        return $version;
    }
}
