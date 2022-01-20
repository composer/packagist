<?php

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
