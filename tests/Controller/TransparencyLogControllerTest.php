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
use App\Log\AuditLogEventType;
use App\Tests\IntegrationTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class TransparencyLogControllerTest extends IntegrationTestCase
{
    public function testShowsProjectedEventsAnonymously(): void
    {
        $this->givenProjectedLog();

        // No authentication set up: the request is anonymous.
        $crawler = $this->client->request('GET', '/transparency-log');
        static::assertResponseIsSuccessful();

        $types = $crawler->filter('[data-test="transparency-log-type"]')->each(fn ($element) => trim($element->text()));
        static::assertContains('Package created', $types);
    }

    public function testUnprojectedAuditRecordsAreNotShown(): void
    {
        $user = self::createUser('unprojected', 'unprojected@example.com');
        $organization = self::createOrganization('acme', 'ACME Corp');
        $this->store($user, $organization);

        // organization_created is out of scope for the projection, so it never reaches this page.
        $this->store(AuditRecord::organizationCreated($organization->id, $organization->slug, $organization->displayName, $user));

        $this->runProjector();

        $crawler = $this->client->request('GET', '/transparency-log');
        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('[data-test="transparency-log-type"]'));
    }

    public function testFiltersByPackageVendorAndType(): void
    {
        $this->givenProjectedLog();

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

    public function testTwoFactorEventsAreHiddenEvenWhenRequestedExplicitly(): void
    {
        $user = self::createUser('hidden', 'hidden@example.com');
        $this->store($user);
        $package = self::createPackage('vendor1/package1', 'https://github.com/vendor1/package1', maintainers: [$user]);
        $this->store($package);

        $this->getEM()->getRepository(AuditRecord::class)->insert(AuditRecord::twoFactorAuthenticationDeactivated($user, $user, 'x'));
        $this->runProjector();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query([
            'type' => [TransparencyLogType::TwoFaDeactivated->value],
        ]));
        static::assertResponseIsSuccessful();

        // The hidden type is dropped from the filter, so this falls back to the unfiltered list, which
        // itself excludes it.
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

    private function runProjector(): void
    {
        $tester = new CommandTester(self::getService(ProjectTransparencyLogCommand::class));
        $tester->execute(['--min-event-age-to-project' => '0']);
        $tester->assertCommandIsSuccessful();
    }
}
