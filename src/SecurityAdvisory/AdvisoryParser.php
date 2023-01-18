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

namespace App\SecurityAdvisory;

use Composer\Pcre\Preg;

class AdvisoryParser
{
    public static function isValidCve(?string $cve): bool
    {
        return $cve && Preg::isMatch('#^CVE-[0-9]{4}-[0-9]{4,}$#', $cve);
    }

    public static function titleWithoutCve(string $title): string
    {
        if (Preg::isMatchStrictGroups('#^(CVE-[0-9a-z*?-]+:)(.*)$#i', $title, $matches)) {
            return trim($matches[2]);
        }

        return $title;
    }
}
