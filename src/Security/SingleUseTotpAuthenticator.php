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

use Predis\Client;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator('scheb_two_factor.security.totp_authenticator')]
class SingleUseTotpAuthenticator implements TotpAuthenticatorInterface
{
    public function __construct(
        #[AutowireDecorated] private TotpAuthenticatorInterface $authenticator,
        private Client $redisCache,
        #[Autowire(param: 'scheb_two_factor.totp.leeway')] private int $leeway,
    ) {
    }

    public function checkCode(TwoFactorInterface $user, string $code): bool
    {
        $code = str_replace(' ', '', $code);

        if (!$this->authenticator->checkCode($user, $code)) {
            return false;
        }

        $configuration = $user->getTotpAuthenticationConfiguration();
        \assert($configuration !== null);
        // Key by the authenticator secret so enrollment and later checks share the same record
        $key = 'totp-used:'.hash_hmac('sha256', $code, $configuration->getSecret());
        $lifetime = $configuration->getPeriod() + 2 * $this->leeway;

        return (string) $this->redisCache->set($key, '1', 'EX', $lifetime, 'NX') === 'OK';
    }

    public function generateSecret(): string
    {
        return $this->authenticator->generateSecret();
    }

    public function getQRContent(TwoFactorInterface $user): string
    {
        return $this->authenticator->getQRContent($user);
    }
}
