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
 * Expected shape of a published attribute, see {@see TransparencyLogScrubber}.
 */
enum TransparencyLogAttribute
{
    /** String, int or bool. */
    case Scalar;
    /** {id, username} with other keys dropped, or a fallback string such as 'automation'. */
    case User;
    /** One invalid item rejects the whole list. */
    case UserList;
    /** Reduced to the artifact identity. Never rejected, dropped when nothing is left. */
    case VersionMetadata;
}
