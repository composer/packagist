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

namespace App\Tests\Entity;

use App\Audit\TransparencyLogType;
use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\PackageTransparencyLog;
use App\Entity\PackageTransparencyLogRepository;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * insertProjected() ignores exactly one error, the (sourceAuditLogId, packageId) dedupe. Everything
 * else must surface, because the projector reads a 0 return as "already projected" and would
 * otherwise drop the event or commit a corrupt leaf.
 */
class PackageTransparencyLogRepositoryTest extends IntegrationTestCase
{
    public function testAlreadyProjectedSourceAndPackageReturnsZero(): void
    {
        $package = $this->storePackage('ptl/dedupe');
        $repo = self::getService(PackageTransparencyLogRepository::class);
        $leafIndex = $repo->getMaxLeafIndex() + 1;
        $source = AuditRecord::packageCreated($package, null);

        self::assertSame(1, $repo->insertProjected($this->entry($source, $package, $leafIndex)));

        // A fresh leaf index, so only the (source, package) pair can be what collides.
        self::assertSame(0, $repo->insertProjected($this->entry($source, $package, $leafIndex + 1)));
        self::assertSame(1, $this->countRowsFor($source));
    }

    public function testLeafIndexCollisionIsNotSwallowed(): void
    {
        $package = $this->storePackage('ptl/leafcollision');
        $repo = self::getService(PackageTransparencyLogRepository::class);
        $leafIndex = $repo->getMaxLeafIndex() + 1;

        $first = AuditRecord::packageCreated($package, null);
        self::assertSame(1, $repo->insertProjected($this->entry($first, $package, $leafIndex)));

        // A different source event, so the dedupe does not apply, reusing an occupied leaf index.
        // INSERT IGNORE used to report this as "already projected" and silently drop the event.
        $second = AuditRecord::packageCreated($package, null);
        self::assertNotSame($first->id->toRfc4122(), $second->id->toRfc4122());

        $this->expectException(UniqueConstraintViolationException::class);
        $repo->insertProjected($this->entry($second, $package, $leafIndex));
    }

    public function testOversizedValueIsNotSilentlyTruncated(): void
    {
        $package = $this->storePackage('ptl/truncation');
        $repo = self::getService(PackageTransparencyLogRepository::class);
        $source = AuditRecord::packageCreated($package, null);

        // vendor is VARCHAR(255); INSERT IGNORE used to store this truncated *and report 1 affected
        // row*, consuming a leaf index for a permanently corrupt entry.
        $entry = $this->entry($source, $package, $repo->getMaxLeafIndex() + 1, str_repeat('a', 256));

        try {
            $repo->insertProjected($entry);
            self::fail('Expected the oversized vendor to be rejected');
        } catch (DriverException $e) {
            self::assertNotInstanceOf(UniqueConstraintViolationException::class, $e);
            self::assertStringContainsStringIgnoringCase('too long', $e->getMessage());
        }

        self::assertSame(0, $this->countRowsFor($source));
    }

    private function entry(AuditRecord $source, Package $package, int $leafIndex, ?string $vendor = null): PackageTransparencyLog
    {
        return PackageTransparencyLog::project(
            $source,
            TransparencyLogType::PackageCreated,
            $leafIndex,
            ['name' => $package->getName()],
            $package->getId(),
            $vendor ?? $package->getVendor(),
        );
    }

    private function countRowsFor(AuditRecord $source): int
    {
        return (int) self::getService(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM package_transparency_log WHERE sourceAuditLogId = ?',
            [$source->id->toBinary()],
        );
    }

    private function storePackage(string $name): Package
    {
        $package = self::createPackage($name, 'https://github.com/'.$name);
        $this->store($package);

        return $package;
    }
}
