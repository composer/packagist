<?php declare(strict_types=1);

namespace App\SecurityAdvisory;

use Composer\Pcre\Preg;

class AdvisoryParser
{
    public static function isValidCve(?string $cve): bool
    {
        return $cve && Preg::match('#^CVE-[0-9]{4}-[0-9]{4,}$#', $cve);
    }

    public static function titleWithoutCve(string $title): string
    {
        if (Preg::match('#^(CVE-[0-9a-z*?-]+:)(.*)$#i', $title, $matches)) {
            return trim($matches[2]);
        }

        return $title;
    }
}
