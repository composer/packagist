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
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TotpCodeEvents;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorCodeEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class TotpAuthenticatorStub implements TotpAuthenticatorInterface
{
    public const MOCKED_VALID_CODE = '999999';

    public function __construct(
        private readonly TotpFactory $totpFactory,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    public function checkCode(TwoFactorInterface $user, string $code): bool
    {
        $event = new TwoFactorCodeEvent($user, $code);
        $this->events->dispatch($event, TotpCodeEvents::CHECK);
        $code = str_replace(' ', '', $code);
        if ($code === '') {
            return false;
        }
        $valid = $code === self::MOCKED_VALID_CODE;
        $this->events->dispatch($event, $valid ? TotpCodeEvents::VALID : TotpCodeEvents::INVALID);

        return $valid;
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
