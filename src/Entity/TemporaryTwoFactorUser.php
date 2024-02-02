<?php declare(strict_types=1);

namespace App\Entity;

use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;

class TemporaryTwoFactorUser implements TwoFactorInterface
{
    public function __construct(
        private readonly User $user,
        private readonly string $totpSecret,
    ) {
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return true;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->user->getTotpAuthenticationUsername();
    }

    public function getTotpAuthenticationConfiguration(): TotpConfigurationInterface
    {
        return new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }
}
