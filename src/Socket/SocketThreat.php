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

namespace App\Socket;

/**
 * One item of Socket's threat feed, for a single version of a Composer package.
 */
final readonly class SocketThreat
{
    public function __construct(
        public string $packageName,
        public string $version,
        public string $type,
        public bool $needsHumanReview,
        public ?string $link,
    ) {
    }
}
