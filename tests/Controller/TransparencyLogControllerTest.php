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

use App\Audit\TransparencyLogType;
use App\Command\ProjectTransparencyLogCommand;
use App\Entity\AuditRecord;
use App\Entity\PackageFreezeReason;
use App\Log\AuditLogEventType;
use App\Tests\IntegrationTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class TransparencyLogControllerTest extends IntegrationTestCase
{
    public function testAnonymousVisitorsAreSentToLogin(): void
    {
        $this->client->request('GET', '/transparency-log');

        static::assertResponseRedirects('/login/');
    }

    public function testShowsProjectedEvents(): void
    {
        $this->givenProjectedLog();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log');
        static::assertResponseIsSuccessful();

        $types = $crawler->filter('[data-test="transparency-log-type"]')->each(fn ($element) => trim($element->text()));
        static::assertContains('Package created', $types);
    }

    public function testPageIsNotIndexable(): void
    {
        $this->givenProjectedLog();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('meta[name="robots"][content="noindex"]'));
    }

    public function testUnprojectedAuditRecordsAreNotShown(): void
    {
        $user = self::createUser('unprojected', 'unprojected@example.com');
        $organization = self::createOrganization('acme', 'ACME Corp');
        $this->store($user, $organization);

        // organization_created is out of scope for the projection, so it never reaches this page.
        $this->store(AuditRecord::organizationCreated($organization->id, $organization->slug, $organization->displayName, $user));

        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log');
        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('[data-test="transparency-log-type"]'));
    }

    public function testFiltersByPackageVendorAndType(): void
    {
        $this->givenProjectedLog();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['package' => 'vendor1/package1']));
        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('[data-test="transparency-log-type"]'));

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['vendor' => 'vendor1']));
        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('[data-test="transparency-log-type"]'));

        // A vendor with nothing projected returns an empty, non-crashing result.
        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['vendor' => 'nobody']));
        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('[data-test="transparency-log-type"]'));

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query([
            'type' => [TransparencyLogType::VersionCreated->value],
        ]));
        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('[data-test="transparency-log-type"]'));
    }

    public function testFreezeReasonIsRenderedAsALabelNotARawEnumValue(): void
    {
        $admin = self::createUser('freezer', 'freezer@example.org', roles: ['ROLE_ADMIN']);
        $this->store($admin);
        $package = self::createPackage('acme/frozen-log', 'https://github.com/acme/frozen-log');
        $this->store($package);

        $this->getEM()->getRepository(AuditRecord::class)->insert(
            AuditRecord::packageFrozen($package, $admin, PackageFreezeReason::RemoteIdMismatch),
        );

        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['package' => 'acme/frozen-log']));

        static::assertResponseIsSuccessful();
        $details = $crawler->filter('td.audit-log-details')->text();
        static::assertStringContainsString('Repository ID mismatch', $details);
        static::assertStringNotContainsString('remote_id', $details);
    }

    public function testTwoFactorEventsAreHiddenEvenWhenRequestedExplicitly(): void
    {
        $user = self::createUser('hidden', 'hidden@example.com');
        $this->store($user);
        $package = self::createPackage('vendor1/package1', 'https://github.com/vendor1/package1', maintainers: [$user]);
        $this->store($package);

        $this->getEM()->getRepository(AuditRecord::class)->insert(AuditRecord::twoFactorAuthenticationDeactivated($user, $user, 'x'));
        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query([
            'type' => [TransparencyLogType::TwoFaDeactivated->value],
        ]));
        static::assertResponseIsSuccessful();

        // The hidden type is dropped from the filter, so this falls back to the unfiltered list, which
        // itself excludes it. The rows are still projected, see TransparencyLogProjectorTest.
        $types = $crawler->filter('[data-test="transparency-log-type"]')->each(fn ($element) => trim($element->text()));
        static::assertNotContains('Maintainer disabled two-factor authentication', $types);
        static::assertContains('Package created', $types);
    }

    private function givenProjectedLog(): void
    {
        $user = self::createUser('projected', 'projected@example.com');
        $this->store($user);
        $package = self::createPackage('vendor1/package1', 'https://github.com/vendor1/package1', maintainers: [$user]);
        $this->store($package);

        $this->runProjector();
    }

    /**
     * The log is only readable by logged in users, and the reader is deliberately not involved in any of
     * the events under test.
     */
    private function givenLoggedInVisitor(): void
    {
        $visitor = self::createUser('visitor', 'visitor@example.com');
        $this->store($visitor);

        $this->client->loginUser($visitor);
    }

    private function runProjector(): void
    {
        $tester = new CommandTester(self::getService(ProjectTransparencyLogCommand::class));
        $tester->execute(['--min-event-age-to-project' => '0']);
        $tester->assertCommandIsSuccessful();
    }
}
