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

final class Rfc3339
{
    /**
     * Parses an RFC 3339 date-time (e.g. "2026-10-18T14:30:00Z") into UTC. Anything else gives null,
     * including the relative dates ("yesterday") that the DateTimeImmutable constructor would accept.
     */
    public static function parse(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value)
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $value);

        return $date ? $date->setTimezone(new \DateTimeZone('UTC')) : null;
    }
}
