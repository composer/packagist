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

namespace App\QueryFilter;

/**
 * Builds LIKE patterns for filters, keeping the set of escaped metacharacters in one place.
 */
final class LikePattern
{
    /**
     * Escape LIKE metacharacters so $value is matched literally.
     */
    public static function escape(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * Pattern matching $value anywhere in the column.
     */
    public static function contains(string $value): string
    {
        return '%'.self::escape($value).'%';
    }
}
