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

namespace App\Support\Attributes;

use Composer\Pcre\Preg;

/** One package's requested move, as the requester asked for it. */
final readonly class PackageUrlChange
{
    public function __construct(
        public string $packageName,
        public string $repository,
    ) {
    }

    /** Also understands the git@host:path form, which parse_url() returns no host for. */
    public static function hostOf(string $url): ?string
    {
        if (Preg::isMatch('{^[a-z0-9._-]+@([a-z0-9.-]+):[^/]}i', $url, $match)) {
            return $match[1];
        }

        $host = parse_url($url, \PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
