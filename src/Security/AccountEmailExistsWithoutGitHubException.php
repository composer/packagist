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

use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

class AccountEmailExistsWithoutGitHubException extends CustomUserMessageAuthenticationException
{
    public function __construct(string $email)
    {
        parent::__construct(
            'An account with your GitHub email ('.$email.') already exists on Packagist.org but it is not linked to your GitHub account. '
            .'Please log in to it via username/password and then connect your GitHub account from the Profile > Settings page.'
        );
    }
}
