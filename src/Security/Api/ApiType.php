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

namespace App\Security\Api;

/**
 * Voter attributes for what an API request was authenticated with.
 */
enum ApiType: string
{
    /** The main or the safe API token. The safe one is meant to be leak-tolerant, so it only allows reads or non-destructive actions like updates. */
    case Safe = 'API_SAFE';
    /** The main API token only. */
    case Unsafe = 'API_UNSAFE';
}
