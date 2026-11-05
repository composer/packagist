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

final readonly class RepositoryApiCheck
{
    /**
     * @param int|null $statusCode the API's answer for a missing repository, recorded in the audit log
     */
    public function __construct(
        public RepositoryApiState $state,
        public ?int $statusCode = null,
    ) {
    }
}
