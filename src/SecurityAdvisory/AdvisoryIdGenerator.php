<?php declare(strict_types=1);

namespace App\SecurityAdvisory;

class AdvisoryIdGenerator
{
    // All alphanumeric symbols except vowels and some to avoid misspellings (I, O, l, 0), case insensitive, 34 character alphabet
    private const ALNUM_SAFE_CI = "bcdfghjkmnpqrstvwxyz123456789";

    public static function generate(): string
    {
        $letterPool = self::ALNUM_SAFE_CI;
        $token = 'PKSA-';
        $len = strlen($letterPool) - 1;
        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 4; $j++) {
                $token .= $letterPool[random_int(0, $len)];
            }

            $token .= '-';
        }

        return trim($token, '-');
    }
}
