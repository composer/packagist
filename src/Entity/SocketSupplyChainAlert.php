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

namespace App\Entity;

use App\Socket\SupplyChainAlertType;
use Doctrine\ORM\Mapping as ORM;

/**
 * A supply chain risk Socket reports for a package version. Shown on package pages only, never part
 * of the Composer metadata, so unlike the malware filter list it cannot block installs.
 */
#[ORM\Entity(repositoryClass: SocketSupplyChainAlertRepository::class)]
#[ORM\Table(name: 'socket_supply_chain_alert')]
#[ORM\UniqueConstraint(name: 'socket_supply_chain_alert_uniq', columns: ['packageName', 'version', 'type'])]
class SocketSupplyChainAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    public function __construct(
        #[ORM\Column(length: 191)]
        public readonly string $packageName,
        #[ORM\Column(length: 255)]
        public readonly string $version,
        #[ORM\Column(length: 32)]
        public readonly SupplyChainAlertType $type,
        #[ORM\Column(length: 2048, nullable: true)]
        public readonly ?string $link,
        #[ORM\Column(nullable: true)]
        public readonly ?\DateTimeImmutable $reportedAt = null,
    ) {
    }
}
