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

namespace App\Log;

/**
 * The role of a person in package_transparency_log_search. Each role has its own filter, as in the
 * audit log.
 *
 *  - `user`: the person the event is about, including the maintainers of a transfer.
 *  - `actor`: the person who did it.
 *
 * Values must be max 16 chars long.
 */
enum TransparencyLogSearchType: string
{
    case User = 'user';
    case Actor = 'actor';
}
