<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;

class AccountEmailExistsWithoutGitHubException extends UsernameNotFoundException
{
    private string $email;

    public function __construct(string $email)
    {
        $this->email = $email;
    }

    /**
     * {@inheritdoc}
     */
    public function getMessageKey()
    {
        return 'An account with your GitHub email ('.$this->email.') already exists on Packagist.org but it is not linked to your GitHub account. '
            . 'Please log in to it via username/password and then connect your GitHub account from the Profile > Settings page.';
    }
}
