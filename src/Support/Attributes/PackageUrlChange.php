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

/** One package's requested move, as the requester asked for it. */
final readonly class PackageUrlChange
{
    public function __construct(
        public string $packageName,
        public string $repository,
    ) {
    }
}
