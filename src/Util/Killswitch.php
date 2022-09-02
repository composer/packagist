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

/**
 * Allows easily disabling some functionality
 */
class Killswitch
{
    // package metadata update and other background workers
    public const WORKERS_ENABLED = true;

    // dependent/suggester counts and pages
    public const LINKS_ENABLED = true;

    // download stats pages (global and per package)
    public const DOWNLOADS_ENABLED = true;

    // package page details (security advisories, forms, dependent/suggester counts)
    public const PAGE_DETAILS_ENABLED = true;

    // package pages
    public const PAGES_ENABLED = true;

    /**
     * Silly workaround to avoid phpstan reporting "this condition is always true/false" when using the constants directly
     * @param self::* $feature
     */
    public static function isEnabled(bool $feature): bool
    {
        return $feature;
    }
}
