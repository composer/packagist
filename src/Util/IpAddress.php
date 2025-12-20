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

namespace App\Util;

use Composer\Pcre\Preg;

class IpAddress
{
    public static function stringToBinary(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $binary = inet_pton($value);
        if ($binary === false) {
            throw new \InvalidArgumentException('Invalid IP address: ' . $value);
        }

        return $binary;
    }

    public static function binaryToString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $ip = inet_ntop($value);
        if ($ip === false) {
            throw new \InvalidArgumentException('Invalid binary IP address stored in database');
        }

        return $ip;
    }
}
