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

enum RepositoryApiState
{
    /** The API reports the repository as missing while a known public repository is visible */
    case Missing;
    /** The API returned the repository */
    case Exists;
    /** The API failed in another way, or the host itself looks down */
    case Unknown;
}
