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

use Composer\Downloader\TransportException;

/**
 * Base class for failures reading a repository over git's smart HTTP protocol
 *
 * VcsRepository skips the version being read on any exception but a TransportException with a
 * 401/403/429/5xx code, and Packagist then deletes a skipped version as gone upstream. Every
 * failure here concerns the whole repository, so it carries such a code to abort the update instead.
 */
class GitException extends TransportException
{
    public function __construct(string $message, ?int $statusCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode !== null && ($statusCode >= 500 || \in_array($statusCode, [401, 403, 429], true)) ? $statusCode : 500, $previous);
        $this->setStatusCode($statusCode);
    }
}
