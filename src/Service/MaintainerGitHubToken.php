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

namespace App\Service;

final readonly class MaintainerGitHubToken
{
    public function __construct(
        #[\SensitiveParameter]
        public string $token,
        public string $username,
        /** The GitHub owner of the repository the token is used for */
        public string $owner,
    ) {
    }
}
