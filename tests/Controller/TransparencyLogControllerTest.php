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
use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class TransparencyLogControllerTest extends IntegrationTestCase
{
    #[DataProvider('filterProvider')]
    public function testViewAuditLogs(array $filters, array $expected): void
    {
        $user = self::createUser('testuser', 'test@example.com', roles: ['ROLE_USER']);
        $package = self::createPackage('vendor1/package1', 'https://github.com/vendor1/package1', maintainers: [$user]);

        $this->store($user, $package);

        $auditRecord1 = AuditRecord::canonicalUrlChange($package, $user, 'https://github.com/vendor1/package1-new');
        $auditRecord = AuditRecord::packageDeleted($package, $user);

        $this->store($auditRecord1, $auditRecord);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/transparency-log?' . http_build_query($filters));
        static::assertResponseIsSuccessful();

        $rows = $crawler->filter('[data-test=audit-log-type]');
        static::assertSame($expected, $rows->each(fn ($element) => trim($element->text())));
    }

    public static function filterProvider(): iterable
    {
        yield [
            [],
            ['Package deleted', 'Canonical URL changed', 'Package created', 'User created'],
        ];

        yield [
            ['type' => [AuditRecordType::CanonicalUrlChanged->value, AuditRecordType::PackageDeleted->value]],
            ['Package deleted', 'Canonical URL changed'],
        ];
    }

    public function testViewAuditLogsWithDateTimeFilter(): void
    {
        $user = self::createUser('testuser', 'test@example.com', roles: ['ROLE_USER']);
        $package = self::createPackage('vendor1/package1', 'https://github.com/vendor1/package1', maintainers: [$user]);

        $this->store($user, $package);

        $auditRecord1 = AuditRecord::canonicalUrlChange($package, $user, 'https://github.com/vendor1/package1-new');
        $auditRecord2 = AuditRecord::packageDeleted($package, $user);

        $this->store($auditRecord1, $auditRecord2);

        $this->client->loginUser($user);

        $now = new \DateTimeImmutable();
        $from = $now->modify('-1 hour')->format('Y-m-d\TH:i:s');
        $to = $now->modify('+1 hour')->format('Y-m-d\TH:i:s');

        $crawler = $this->client->request('GET', '/transparency-log?' . http_build_query([
            'datetime_from' => $from,
            'datetime_to' => $to,
        ]));
        static::assertResponseIsSuccessful();

        $rows = $crawler->filter('[data-test=audit-log-type]');
        static::assertSame(4, $rows->count(), 'Should have 4 results within the time range');

        $timeRangeAlert = $crawler->filter('.audit-log-time-range');
        static::assertCount(1, $timeRangeAlert, 'Time range should be displayed');
    }
}
