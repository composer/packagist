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

namespace App\EventListener;

use App\Security\Passport\Badge\ResolvedTwoFactorCodeCredentials;
use Scheb\TwoFactorBundle\Security\Http\Authenticator\TwoFactorAuthenticator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;

#[AsEventListener(event: AuthenticationTokenCreatedEvent::class, method: 'onAuthenticationTokenCreated', priority: 512)]
class ResolvedTwoFactorCodeCredentialsListener
{
    public function onAuthenticationTokenCreated(AuthenticationTokenCreatedEvent $event): void
    {
        if ($event->getPassport()->getBadge(ResolvedTwoFactorCodeCredentials::class)) {
            $event->getAuthenticatedToken()->setAttribute(TwoFactorAuthenticator::FLAG_2FA_COMPLETE, true);
        }
    }
}
