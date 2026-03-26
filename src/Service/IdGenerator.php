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

namespace App\Service;

class IdGenerator
{
    // All alphanumeric symbols except vowels and some to avoid misspellings (I, O, l, 0), case insensitive, 34 character alphabet
    private const ALNUM_SAFE_CI = 'bcdfghjkmnpqrstvwxyz123456789';

    public static function generateSecurityAdvisoryId(): string
    {
        return self::generate('PKSA-');

    }

    public static function generateFilterListEntry(): string
    {
        return self::generate('PKFE-');

    }

    private static function generate(string $prefix): string
    {
        $letterPool = self::ALNUM_SAFE_CI;
        $token = $prefix;
        $len = \strlen($letterPool) - 1;
        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 4; $j++) {
                $token .= $letterPool[random_int(0, $len)];
            }

            $token .= '-';
        }

        return trim($token, '-');
    }
}
