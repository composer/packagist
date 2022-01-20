<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UserNotFoundException;

class AccountEmailExistsWithoutGitHubException extends UserNotFoundException
{
    public function __construct(private string $email)
    {
    }

    /**
     * @inheritDoc
     */
    public function getMessageKey(): string
    {
        return 'An account with your GitHub email ('.$this->email.') already exists on Packagist.org but it is not linked to your GitHub account. '
            . 'Please log in to it via username/password and then connect your GitHub account from the Profile > Settings page.';
    }
}
