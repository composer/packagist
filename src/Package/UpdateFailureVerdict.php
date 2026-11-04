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

final readonly class UpdateFailureVerdict
{
    /**
     * @param string $code stable identifier of the matched failure, recorded in the audit log
     */
    public function __construct(
        public UpdateFailureKind $kind,
        public string $code,
    ) {
    }
}
