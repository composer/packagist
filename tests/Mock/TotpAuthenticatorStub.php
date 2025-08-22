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

namespace App\Tests\Mock;

use ParagonIE\ConstantTime\Base32;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpFactory;

class TotpAuthenticatorStub implements TotpAuthenticatorInterface
{
    public const MOCKED_VALID_CODE = '999999';

    public function __construct(
        private readonly TotpFactory $totpFactory,
    ) {
    }

    public function checkCode(TwoFactorInterface $user, string $code): bool
    {
        return $code === self::MOCKED_VALID_CODE;
    }

    public function getQRContent(TwoFactorInterface $user): string
    {
        return $this->totpFactory->createTotpForUser($user)->getProvisioningUri();
    }

    public function generateSecret(): string
    {
        return Base32::encodeUpperUnpadded(random_bytes(32));
    }
}
