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

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UserNotFoundException;

class NoVerifiedGitHubEmailFoundException extends UserNotFoundException
{
    public function getMessageKey(): string
    {
        return 'No verified email address was found on your GitHub account, so we can not automatically log you in. '
            . 'Please register an account manually and then connect your GitHub account from the Profile > Settings page.';
    }
}
