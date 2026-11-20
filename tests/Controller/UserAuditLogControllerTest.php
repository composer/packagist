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
use App\Entity\User;
use App\Entity\UserFreezeReason;
use App\Tests\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

class UserAuditLogControllerTest extends IntegrationTestCase
{
    public function testRequiresAuthentication(): void
    {
        $this->client->request('GET', '/profile/security/audit-log');

        static::assertResponseRedirects();
        static::assertStringEndsWith('/login/', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testListsWhatHappenedToTheAccountAndWhatTheUserDid(): void
    {
        [$alice, $bob] = $this->givenAliceAndBob();
        $package = self::createPackage('vendor1/package1', 'https://github.com/vendor1/package1', maintainers: [$alice]);
        $this->store($package);

        $this->insert(
            AuditRecord::passwordChanged($alice, $alice),
            AuditRecord::userFrozen($alice, $bob, UserFreezeReason::Temporary, 'pending review'),
            AuditRecord::packageDeleted($package, $alice),
            AuditRecord::passwordChanged($bob, $bob),
            AuditRecord::emailChanged($bob, $bob, 'old-bob@example.org'),
        );

        $crawler = $this->requestAs($alice);

        static::assertSame(
            ['Package deleted', 'User frozen', 'Password changed', 'Package created', 'User created'],
            $crawler->filter('[data-test=log-type]')->each(static fn (Crawler $element): string => trim($element->text())),
        );
        static::assertStringNotContainsString('bob@example.org', $crawler->filter('.audit-log-table')->text());
    }

    public function testUserSeesNoAdminOnlyDetails(): void
    {
        [$alice, $bob] = $this->givenAliceAndBob();
        $this->insert(
            AuditRecord::userFrozen($alice, $bob, UserFreezeReason::Temporary, 'pending review', 'reported by jane'),
            AuditRecord::emailChanged($alice, $alice, 'old-alice@example.org'),
        );

        $crawler = $this->requestAs($alice);

        $details = $crawler->filter('.audit-log-table')->text();
        static::assertStringContainsString('Reason: pending review', $details);
        static::assertStringNotContainsString('reported by jane', $details);
        static::assertStringNotContainsString('IP:', $details);
        static::assertStringContainsString('From: old-alice@example.org', $details);
        static::assertCount(0, $crawler->filter('.audit-log-ip'));
        static::assertCount(0, $crawler->filter('.admin-only'));
        static::assertCount(0, $crawler->filter('[data-test=admin-only-legend]'));
    }

    public function testAuditorSeesAdminOnlyDetailsFlagged(): void
    {
        [$alice, $bob] = $this->givenAliceAndBob();
        $alice->addRole('ROLE_AUDITOR');
        $this->getEM()->flush();
        $this->insert(
            AuditRecord::userFrozen($alice, $bob, UserFreezeReason::Temporary, 'pending review', 'reported by jane'),
            AuditRecord::emailChanged($alice, $alice, 'old-alice@example.org'),
            // done by alice to bob's account, so the address is not hers to see without the role
            AuditRecord::userVerified($bob, $alice, 'bob@example.org'),
        );

        $crawler = $this->requestAs($alice);

        static::assertCount(1, $crawler->filter('[data-test=admin-only-legend]'));
        $flagged = $crawler->filter('.audit-log-details span.admin-only')->each(static fn (Crawler $element): string => trim($element->text()));
        static::assertContains('Internal reason: reported by jane', $flagged);
        static::assertContains('bob@example.org', $flagged);
        static::assertContains('IP:', $flagged);
        static::assertNotContains('old-alice@example.org', $flagged, 'Her own address is shown to her without the role too');
        static::assertCount($crawler->filter('[data-test=log-type]')->count(), $crawler->filter('td.audit-log-ip.admin-only'));
        static::assertStringNotContainsString('Reason: pending review', implode(' ', $flagged));
    }

    public function testFiltersByType(): void
    {
        [$alice] = $this->givenAliceAndBob();
        $this->insert(
            AuditRecord::passwordChanged($alice, $alice),
            AuditRecord::emailChanged($alice, $alice, 'old-alice@example.org'),
        );

        $crawler = $this->requestAs($alice, ['type' => ['email_changed']]);

        static::assertSame(
            ['Email address changed'],
            $crawler->filter('[data-test=log-type]')->each(static fn (Crawler $element): string => trim($element->text())),
        );
    }

    /**
     * @return array{User, User}
     */
    private function givenAliceAndBob(): array
    {
        $alice = self::createUser('alice', 'alice@example.org');
        $bob = self::createUser('bob', 'bob@example.org');
        $this->store($alice, $bob);

        return [$alice, $bob];
    }

    private function insert(AuditRecord ...$records): void
    {
        // one flush each, as records of the same flush are not ordered among themselves
        foreach ($records as $record) {
            $this->store($record);
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private function requestAs(User $user, array $query = []): Crawler
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/profile/security/audit-log?'.http_build_query($query));
        static::assertResponseIsSuccessful();

        return $crawler;
    }
}
