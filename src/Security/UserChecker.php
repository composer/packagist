<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\User as AppUser;
use Symfony\Component\Security\Core\Exception\AccountExpiredException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isEnabled() || $user->hasRole('ROLE_SPAMMER')) {
            throw new CustomUserMessageAccountStatusException('Your user account is not yet enabled. Please make sure you confirm your email address or trigger a password reset to receive another email.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
