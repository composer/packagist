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

final readonly class V2DumpResult
{
    /**
     * @param list<int> $leftStale ids handed to the run that were not dumped: failed, or never reached after an abort
     */
    public function __construct(
        public V2DumpOutcome $outcome,
        public array $leftStale,
    ) {
    }
}
