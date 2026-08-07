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

use App\Command\ProjectTransparencyLogCommand;
use App\Entity\AuditRecord;
use App\Entity\PackageFreezeReason;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Tester\CommandTester;

class PackageTransparencyLogControllerTest extends IntegrationTestCase
{
    public function testPublicPageRendersAnonymously(): void
    {
        $em = $this->getEM();

        $package = self::createPackage('acme/public-log', 'https://github.com/acme/public-log');
        $em->persist($package);
        $em->flush();

        $this->runProjector();

        // No authentication set up: the request is anonymous.
        $crawler = $this->client->request('GET', '/packages/acme/public-log/transparency');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Transparency Log', $crawler->html());
        self::assertSame(1, $crawler->filter('[data-test="transparency-log-type"]')->count());
        self::assertStringContainsString('Package created', $crawler->filter('[data-test="transparency-log-type"]')->text());
    }

    public function testPageIsNotIndexable(): void
    {
        $em = $this->getEM();

        $package = self::createPackage('acme/noindex', 'https://github.com/acme/noindex');
        $em->persist($package);
        $em->flush();

        $this->runProjector();

        $crawler = $this->client->request('GET', '/packages/acme/noindex/transparency');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('meta[name="robots"][content="noindex"]'));
    }

    public function testFreezeReasonIsRenderedAsALabelNotARawEnumValue(): void
    {
        $em = $this->getEM();

        $admin = self::createUser('freezer', 'freezer@example.org', roles: ['ROLE_ADMIN']);
        $em->persist($admin);
        $package = self::createPackage('acme/frozen-log', 'https://github.com/acme/frozen-log');
        $em->persist($package);
        $em->flush();

        $em->getRepository(AuditRecord::class)->insert(
            AuditRecord::packageFrozen($package, $admin, PackageFreezeReason::RemoteIdMismatch),
        );

        $this->runProjector();

        $crawler = $this->client->request('GET', '/packages/acme/frozen-log/transparency');

        self::assertResponseIsSuccessful();
        $details = $crawler->filter('td.audit-log-details')->text();
        self::assertStringContainsString('Repository ID mismatch', $details);
        self::assertStringNotContainsString('remote_id', $details);
    }

    public function testTwoFactorEventsAreProjectedButHiddenFromThePublicPage(): void
    {
        $em = $this->getEM();
        $conn = self::getService(Connection::class);

        $user = self::createUser('hidden2fa', 'hidden2fa@example.org');
        $em->persist($user);
        $em->flush();

        $package = self::createPackage('acme/hidden-log', 'https://github.com/acme/hidden-log', null, [$user]);
        $em->persist($package);
        $em->flush();

        $em->getRepository(AuditRecord::class)->insert(AuditRecord::twoFactorAuthenticationDeactivated($user, $user, 'x'));
        $em->getRepository(AuditRecord::class)->insert(AuditRecord::twoFactorAuthenticationActivated($user, $user));

        $this->runProjector();

        // Still projected: the hide is display-only.
        self::assertSame(2, (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM package_transparency_log WHERE type IN ('two_fa_activated', 'two_fa_deactivated')",
        ));

        $crawler = $this->client->request('GET', '/packages/acme/hidden-log/transparency');

        self::assertResponseIsSuccessful();
        $types = $crawler->filter('[data-test="transparency-log-type"]');
        self::assertSame(1, $types->count());
        self::assertStringContainsString('Package created', $types->text());
    }

    private function runProjector(): void
    {
        $tester = new CommandTester(self::getService(ProjectTransparencyLogCommand::class));
        $tester->execute(['--min-event-age-to-project' => '0']);
        $tester->assertCommandIsSuccessful();
    }
}
