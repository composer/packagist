<?php declare(strict_types=1);

namespace App\Util;

/**
 * Allows easily disabling some functionality
 */
class Killswitch
{
    // package metadata update and other background workers
    const WORKERS_ENABLED = true;

    // dependent/suggester counts and pages
    const LINKS_ENABLED = true;

    // download stats pages (global and per package)
    const DOWNLOADS_ENABLED = true;

    // package page details (security advisories, forms, dependent/suggester counts)
    const PAGE_DETAILS_ENABLED = true;

    // package pages
    const PAGES_ENABLED = true;

    /**
     * Silly workaround to avoid phpstan reporting "this condition is always true/false" when using the constants directly
     * @param self::* $feature
     */
    public static function isEnabled(bool $feature): bool
    {
        return $feature;
    }
}
