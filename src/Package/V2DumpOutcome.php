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

namespace App\Package;

enum V2DumpOutcome
{
    /** Every package handed to the run was dumped. */
    case Complete;
    /** Some packages failed and were left stale for the next run, the rest were dumped. */
    case SomeFailed;
    /** The CDN or a replica was unreachable, so the run stopped early. What was dumped before that is kept. */
    case Aborted;
}
