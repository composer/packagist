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

enum UpdateFailureKind
{
    /** The repository is conclusively gone, the package gets frozen (or deleted after an API confirmation) */
    case Gone;
    /** The host could not be reached, updates are skipped for a while */
    case Unreachable;
}
