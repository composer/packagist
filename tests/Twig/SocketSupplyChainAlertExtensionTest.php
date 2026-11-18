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

namespace App\Tests\Twig;

use App\Entity\SocketSupplyChainAlert;
use App\Entity\SocketSupplyChainAlertRepository;
use App\Socket\SupplyChainAlertType;
use App\Twig\SocketSupplyChainAlertExtension;
use Doctrine\DBAL\Driver\PDO\Exception as PDOException;
use Doctrine\DBAL\Exception\ConnectionException;
use PHPUnit\Framework\TestCase;

class SocketSupplyChainAlertExtensionTest extends TestCase
{
    public function testGroupsAlertsByTypeInTheOrderOfTheirTopAlert(): void
    {
        $typo = new SocketSupplyChainAlert('vendor/pkg', '1.0.0', SupplyChainAlertType::TYPOSQUAT, null);
        $telemetry1 = new SocketSupplyChainAlert('vendor/pkg', '1.0.0', SupplyChainAlertType::TELEMETRY, null);
        $telemetry2 = new SocketSupplyChainAlert('vendor/pkg', '1.1.0', SupplyChainAlertType::TELEMETRY, null);

        $repo = $this->createStub(SocketSupplyChainAlertRepository::class);
        $repo->method('getPackageAlerts')->willReturn([$typo, $telemetry1, $telemetry2]);

        $this->assertSame([
            ['type' => SupplyChainAlertType::TELEMETRY, 'alerts' => [$telemetry2, $telemetry1], 'more' => 0],
            ['type' => SupplyChainAlertType::TYPOSQUAT, 'alerts' => [$typo], 'more' => 0],
        ], new SocketSupplyChainAlertExtension($repo)->getPackageAlerts('vendor/pkg'));
    }

    public function testOrdersAlertsOfOneVersionByReportTime(): void
    {
        $undated = new SocketSupplyChainAlert('vendor/pkg', '1.0.0', SupplyChainAlertType::TYPOSQUAT, null);
        $later = new SocketSupplyChainAlert('vendor/pkg', '1.0.0', SupplyChainAlertType::TELEMETRY, null, new \DateTimeImmutable('2026-03-01'));
        $earlier = new SocketSupplyChainAlert('vendor/pkg', '1.0.0', SupplyChainAlertType::PROTESTWARE, null, new \DateTimeImmutable('2026-01-01'));

        $repo = $this->createStub(SocketSupplyChainAlertRepository::class);
        $repo->method('getPackageAlerts')->willReturn([$undated, $later, $earlier]);

        $this->assertSame(
            [SupplyChainAlertType::PROTESTWARE, SupplyChainAlertType::TELEMETRY, SupplyChainAlertType::TYPOSQUAT],
            array_column(new SocketSupplyChainAlertExtension($repo)->getPackageAlerts('vendor/pkg'), 'type'),
        );
    }

    public function testListsNewestVersionsFirstAndCapsThem(): void
    {
        $alerts = array_map(
            static fn (string $version) => new SocketSupplyChainAlert('vendor/pkg', $version, SupplyChainAlertType::TYPOSQUAT, null),
            ['1.9.0', 'not a version', '1.10.0', 'v2.0.0', '1.2.0', '0.9.0', '1.0.0'],
        );

        $repo = $this->createStub(SocketSupplyChainAlertRepository::class);
        $repo->method('getPackageAlerts')->willReturn($alerts);

        $groups = new SocketSupplyChainAlertExtension($repo)->getPackageAlerts('vendor/pkg');

        $this->assertCount(1, $groups);
        $this->assertSame(['v2.0.0', '1.10.0', '1.9.0', '1.2.0', '1.0.0'], array_map(static fn (SocketSupplyChainAlert $alert) => $alert->version, $groups[0]['alerts']));
        $this->assertSame(2, $groups[0]['more']);
    }

    public function testReturnsNoAlertsWhenTheDatabaseFails(): void
    {
        $repo = $this->createStub(SocketSupplyChainAlertRepository::class);
        $repo->method('getPackageAlerts')->willThrowException(new ConnectionException(PDOException::new(new \PDOException('gone away')), null));

        $this->assertSame([], new SocketSupplyChainAlertExtension($repo)->getPackageAlerts('vendor/pkg'));
    }
}
