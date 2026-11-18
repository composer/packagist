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

namespace App\Twig;

use App\Entity\SocketSupplyChainAlert;
use App\Entity\SocketSupplyChainAlertRepository;
use App\Socket\SupplyChainAlertType;
use App\Util\VersionReportOrder;
use Twig\Attribute\AsTwigFunction;

class SocketSupplyChainAlertExtension
{
    private const int MAX_VERSIONS_PER_TYPE = 5;

    public function __construct(
        private SocketSupplyChainAlertRepository $alertRepo,
    ) {
    }

    /**
     * Grouped by type, in {@see VersionReportOrder} both within and across groups, at most
     * MAX_VERSIONS_PER_TYPE per type, as a typosquat flags every version of a package.
     *
     * @return list<array{type: SupplyChainAlertType, alerts: non-empty-list<SocketSupplyChainAlert>, more: int}>
     */
    #[AsTwigFunction(name: 'socket_supply_chain_alerts')]
    public function getPackageAlerts(string $packageName): array
    {
        try {
            $alerts = $this->alertRepo->getPackageAlerts($packageName);
        } catch (\Doctrine\DBAL\Exception) {
            return [];
        }

        $alerts = VersionReportOrder::sort($alerts, static fn (SocketSupplyChainAlert $alert) => $alert->version, static fn (SocketSupplyChainAlert $alert) => $alert->reportedAt);

        $byType = [];
        foreach ($alerts as $alert) {
            $byType[$alert->type->value][] = $alert;
        }

        return array_values(array_map(static fn (array $typeAlerts) => [
            'type' => $typeAlerts[0]->type,
            'alerts' => \array_slice($typeAlerts, 0, self::MAX_VERSIONS_PER_TYPE),
            'more' => max(0, \count($typeAlerts) - self::MAX_VERSIONS_PER_TYPE),
        ], $byType));
    }
}
