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

namespace App\Package\Git;

/**
 * A failure that may go away by itself, e.g. a 5xx, a timeout or a DNS failure
 */
final class RepositoryUnavailableException extends GitException
{
}
