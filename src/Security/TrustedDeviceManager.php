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

use Scheb\TwoFactorBundle\Security\TwoFactor\Event\BackupCodeEvents;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\TrustedDeviceManager as BaseTrustedDeviceManager;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\TrustedDeviceTokenStorage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class TrustedDeviceManager extends BaseTrustedDeviceManager
{
    private const string BACKUP_CODE_USED = '_security.backup_code_used';

    public function __construct(
        #[Autowire(service: 'scheb_two_factor.trusted_token_storage')] TrustedDeviceTokenStorage $storage,
        private RequestStack $requests,
    ) {
        parent::__construct($storage);
    }

    #[AsEventListener(event: BackupCodeEvents::VALID)]
    public function onValidBackupCode(): void
    {
        $this->requests->getCurrentRequest()?->attributes->set(self::BACKUP_CODE_USED, true);
    }

    public function canSetTrustedDevice(object $user, Request $request, string $firewallName): bool
    {
        return !$request->attributes->getBoolean(self::BACKUP_CODE_USED) && parent::canSetTrustedDevice($user, $request, $firewallName);
    }
}
