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

        $tester = new CommandTester(self::getService(ProjectTransparencyLogCommand::class));
        $tester->execute(['--min-event-age-to-project' => '0']);
        $tester->assertCommandIsSuccessful();

        // No authentication set up: the request is anonymous.
        $crawler = $this->client->request('GET', '/packages/acme/public-log/transparency');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Transparency Log', $crawler->html());
        self::assertSame(1, $crawler->filter('[data-test="transparency-log-type"]')->count());
        self::assertStringContainsString('Package created', $crawler->filter('[data-test="transparency-log-type"]')->text());
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

        $tester = new CommandTester(self::getService(ProjectTransparencyLogCommand::class));
        $tester->execute(['--min-event-age-to-project' => '0']);
        $tester->assertCommandIsSuccessful();

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
}
