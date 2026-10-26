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

namespace App\Socket;

/**
 * Socket threat types shown as supply chain alerts on package pages. Values are Socket's threatType.
 */
enum SupplyChainAlertType: string
{
    case TYPOSQUAT = 'typosquat';
    case OBFUSCATED = 'obfuscated';
    case TELEMETRY = 'telemetry';
    case PROTESTWARE = 'troll';

    /**
     * Threat feed filter returning this type, along with its unconfirmed variants.
     */
    public function feedFilter(): string
    {
        return match ($this) {
            self::TYPOSQUAT => 'typo',
            self::OBFUSCATED => 'obf',
            self::TELEMETRY => 'spy',
            self::PROTESTWARE => 'joke',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TYPOSQUAT => 'Typosquat',
            self::OBFUSCATED => 'Obfuscated code',
            self::TELEMETRY => 'Telemetry',
            self::PROTESTWARE => 'Protestware',
        };
    }
}
