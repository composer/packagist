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

use App\Entity\AuditRecord;
use App\Tests\IntegrationTestCase;

class AuditLogControllerTest extends IntegrationTestCase
{
    public function testViewAuditLogs(): void
    {
        $user = self::createUser('testuser', 'test@example.com', roles: ['ROLE_ADMIN']);
        $package = self::createPackage('vendor1/package1', 'https://github.com/vendor1/package1', maintainers: [$user]);

        $this->store($user, $package);

        $auditLog1 = AuditRecord::canonicalUrlChange($package, $user, 'https://github.com/vendor1/package1-new');
        $auditLog2 = AuditRecord::packageDeleted($package, $user);

        $this->store($auditLog1, $auditLog2);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/audit-log');
        static::assertResponseIsSuccessful();

        $rows = $crawler->filter('[data-test=audit-log-type]');
        static::assertGreaterThanOrEqual(3, $rows->count(), 'Should have at least 3 audit log entries');
        static::assertSame([
            'package_deleted',
            'canonical_url_change',
            'package_created',
        ], $rows->each(fn ($element) => trim($element->text())));
    }
}
