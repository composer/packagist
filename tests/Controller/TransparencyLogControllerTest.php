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
    public function testViewOrganizationCreatedAuditLog(): void
    {
        $user = self::createUser('orgcreator', 'orgcreator@example.com', roles: ['ROLE_USER']);
        $organization = self::createOrganization('acme', 'ACME Corp', owner: $user);
        $this->store($user, $organization);

        $this->store(AuditRecord::organizationCreated($organization->id, $organization->slug, $organization->displayName, $user));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/transparency-log');
        static::assertResponseIsSuccessful();

        $types = $crawler->filter('[data-test=audit-log-type]')->each(fn ($element) => trim($element->text()));
        static::assertContains('Organization created', $types);

        $link = $crawler->filter('a[href="/organizations/acme"]');
        static::assertCount(1, $link);
        static::assertSame('ACME Corp', trim($link->text()));
    }

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
        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query($filters));
        static::assertResponseIsSuccessful();

        $rows = $crawler->filter('[data-test=audit-log-type]');
        static::assertSame($expected, $rows->each(fn ($element) => trim($element->text())));
    }

    public function testViewAuditLogsFilteredByUsername(): void
    {
        $actor = self::createUser('actoruser', 'actor@example.com', roles: ['ROLE_USER']);
        $maintainer = self::createUser('naderman', 'nader@example.com');
        $package = self::createPackage('vendor1/package1', 'https://github.com/vendor1/package1', maintainers: [$actor]);

        $this->store($actor, $maintainer, $package);
        $this->store(AuditRecord::maintainerAdded($package, $maintainer, $actor));

        $this->client->loginUser($actor);

        // The link on /users/naderman/ hits this exact path; it used to time out scanning the JSON.
        // The search is case-insensitive and matches the record where naderman is the subject user.
        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user' => 'NADERMAN']));
        static::assertResponseIsSuccessful();
        $rowTexts = $crawler->filter('[data-test=audit-log-type]')->each(fn ($element) => trim($element->text()));
        static::assertContains('Maintainer added', $rowTexts);

        // A username with no records returns an empty, non-crashing result
        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user' => 'nobody']));
        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('[data-test=audit-log-type]'));
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

    public function testPackageDeletionReasonsAreRoleGated(): void
    {
        $maintainer = self::createUser('maintainer', 'maintainer@example.com', roles: ['ROLE_USER']);
        $package = self::createPackage('vendor1/package1', 'https://github.com/vendor1/package1', maintainers: [$maintainer]);

        $this->store($maintainer, $package);

        $auditRecord = AuditRecord::packageDeleted($package, $maintainer, 'PUBLIC-REASON-XYZ', 'INTERNAL-REASON-ABC');
        $this->store($auditRecord);

        // A plain user sees the public reason but never the admin-only internal reason.
        $this->client->loginUser($maintainer);
        $this->client->request('GET', '/transparency-log');
        static::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        static::assertStringContainsString('PUBLIC-REASON-XYZ', $body);
        static::assertStringNotContainsString('INTERNAL-REASON-ABC', $body);

        // An admin (implies ROLE_AUDITOR) sees both.
        $admin = self::createUser('adminuser', 'admin@example.com', roles: ['ROLE_ADMIN']);
        $this->store($admin);
        $this->client->loginUser($admin);
        $this->client->request('GET', '/transparency-log');
        static::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        static::assertStringContainsString('PUBLIC-REASON-XYZ', $body);
        static::assertStringContainsString('INTERNAL-REASON-ABC', $body);
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

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query([
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
