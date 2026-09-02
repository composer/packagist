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

namespace App\Tests\Audit;

use App\Audit\AuditRecordType;
use App\Entity\SecurityAdvisory;
use App\SecurityAdvisory\FriendsOfPhpSecurityAdvisoriesSource;
use App\SecurityAdvisory\GitHubSecurityAdvisoriesSource;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use App\Tests\Fixtures\Fixtures;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SecurityAdvisoryAuditRecordTest extends KernelTestCase
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

    public function testSecurityAdvisoryChangesGetRecorded(): void
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        // The advisory targets a package that is available on Packagist so the audit record can be linked to it.
        $package = self::createPackage('acme/package', 'https://example.org/acme/package.git');
        $em->persist($package);
        $em->flush();

        // Created
        $advisory = new SecurityAdvisory($this->remoteAdvisory('GHSA-aaaa-bbbb-cccc'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $em->persist($advisory);
        $em->flush();

        self::assertSame(1, $this->auditCount(AuditRecordType::SecurityAdvisoryCreated));
        $attributes = $this->latestAttributes(AuditRecordType::SecurityAdvisoryCreated);
        self::assertSame('acme/package', $attributes['name']);
        self::assertSame('GHSA-aaaa-bbbb-cccc', $attributes['remoteId']);
        self::assertSame('automation', $attributes['actor']);
        self::assertSame($package->getId(), $this->latestPackageId(AuditRecordType::SecurityAdvisoryCreated));

        // Edited
        $advisory->updateAdvisory($this->remoteAdvisory('GHSA-aaaa-bbbb-cccc', '^2.0'));
        $em->flush();

        self::assertSame(1, $this->auditCount(AuditRecordType::SecurityAdvisoryEdited));
        $attributes = $this->latestAttributes(AuditRecordType::SecurityAdvisoryEdited);
        self::assertArrayHasKey('affectedVersions', $attributes['changes']);
        self::assertSame('^1.0', $attributes['changes']['affectedVersions']['from']);
        self::assertSame('^2.0', $attributes['changes']['affectedVersions']['to']);
        self::assertArrayNotHasKey('updatedAt', $attributes['changes'], 'The bookkeeping timestamp should be excluded from the recorded changes');

        // Withdrawn
        $advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $em->flush();

        self::assertNotNull($advisory->getWithdrawnAt());

        self::assertSame(1, $this->auditCount(AuditRecordType::SecurityAdvisoryWithdrawn));

        // Un-withdrawn: the source reports the advisory as live again, with a changed field.
        $advisory->updateAdvisory($this->remoteAdvisory('GHSA-aaaa-bbbb-cccc', '^3.0'));
        $advisory->reinstateSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME, 'GHSA-aaaa-bbbb-cccc');
        $em->flush();

        self::assertNull($advisory->getWithdrawnAt());

        self::assertSame(1, $this->auditCount(AuditRecordType::SecurityAdvisoryUnwithdrawn));
        self::assertSame(1, $this->auditCount(AuditRecordType::SecurityAdvisoryWithdrawn), 'Un-withdrawing must not be recorded as another withdrawal');

        $attributes = $this->latestAttributes(AuditRecordType::SecurityAdvisoryUnwithdrawn);
        self::assertSame('^2.0', $attributes['changes']['affectedVersions']['from']);
        self::assertSame('^3.0', $attributes['changes']['affectedVersions']['to']);
        self::assertArrayNotHasKey('withdrawnAt', $attributes['changes'], 'The withdrawnAt flip is implied by the record type');
    }

    public function testSourceWithdrawalsGetRecorded(): void
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        $package = self::createPackage('acme/package', 'https://example.org/acme/package.git');
        $em->persist($package);
        $em->flush();

        $advisory = new SecurityAdvisory($this->remoteAdvisory('GHSA-aaaa-bbbb-cccc'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->addSource('acme/package/CVE-2024-12345.yaml', FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME, null);
        $em->persist($advisory);
        $em->flush();

        $advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $em->flush();

        self::assertFalse($advisory->isWithdrawn());
        self::assertSame(1, $this->auditCount(AuditRecordType::SecurityAdvisorySourceWithdrawn));
        self::assertSame(0, $this->auditCount(AuditRecordType::SecurityAdvisoryWithdrawn));

        $attributes = $this->latestAttributes(AuditRecordType::SecurityAdvisorySourceWithdrawn);
        self::assertSame('acme/package', $attributes['name']);
        self::assertSame(GitHubSecurityAdvisoriesSource::SOURCE_NAME, $attributes['source']);
        self::assertSame('GHSA-aaaa-bbbb-cccc', $attributes['remoteId']);
        self::assertFalse($attributes['advisoryWithdrawn']);
        self::assertSame($package->getId(), $this->latestPackageId(AuditRecordType::SecurityAdvisorySourceWithdrawn));

        $advisory->reinstateSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME, 'GHSA-aaaa-bbbb-cccc');
        $em->flush();

        self::assertSame(1, $this->auditCount(AuditRecordType::SecurityAdvisorySourceUnwithdrawn));
        self::assertSame(0, $this->auditCount(AuditRecordType::SecurityAdvisoryUnwithdrawn), 'the advisory was never withdrawn, so only its source is reinstated');
    }

    private function auditCount(AuditRecordType $type): int
    {
        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_log WHERE type = ?', [$type->value]);
    }

    /**
     * @return array<string, mixed>
     */
    private function latestAttributes(AuditRecordType $type): array
    {
        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        $attributes = $connection->fetchOne('SELECT attributes FROM audit_log WHERE type = ? ORDER BY datetime DESC, id DESC LIMIT 1', [$type->value]);

        return json_decode((string) $attributes, true, flags: \JSON_THROW_ON_ERROR);
    }

    private function latestPackageId(AuditRecordType $type): ?int
    {
        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        $packageId = $connection->fetchOne('SELECT packageId FROM audit_log WHERE type = ? ORDER BY datetime DESC, id DESC LIMIT 1', [$type->value]);

        return $packageId === false || $packageId === null ? null : (int) $packageId;
    }

    private function remoteAdvisory(string $remoteId, string $affectedVersions = '^1.0'): RemoteSecurityAdvisory
    {
        return new RemoteSecurityAdvisory(
            $remoteId,
            'Advisory title',
            'acme/package',
            $affectedVersions,
            'https://example.org/'.$remoteId,
            'CVE-2024-12345',
            new \DateTimeImmutable('2024-01-01 00:00:00'),
            null,
            [],
            GitHubSecurityAdvisoriesSource::SOURCE_NAME,
            null,
        );
    }
}
