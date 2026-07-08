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
use App\Tests\Fixtures\Fixtures;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class VersionAuditRecordTest extends KernelTestCase
{
    use Fixtures;

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
        self::assertArrayHasKey('published-time', $attributes['metadata']);
    }

    private function createPackageAndVersion(): Version
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $package = self::createPackage('composer/composer', 'https://github.com/composer/composer');

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
