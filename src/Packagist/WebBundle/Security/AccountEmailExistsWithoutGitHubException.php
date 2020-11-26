<?php

namespace Packagist\WebBundle\Security;

use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;

class AccountEmailExistsWithoutGitHubException extends UsernameNotFoundException
{
    /**
     * {@inheritdoc}
     */
    public function getMessageKey()
    {
        return 'An account with your GitHub email already exists on Packagist.org but is not linked to your GitHub account. Please login to it via email/password and then connect your GitHub account in your settings.';
    }
}
