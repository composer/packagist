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
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * @author Colin O'Dell <colinodell@gmail.com>
 */
class TwoFactorAuthRateLimiter implements EventSubscriberInterface
{
    public const MAX_ATTEMPTS = 5;
    public const RATE_LIMIT_DURATION = 15; // in minutes

    public function __construct(private Client $redisCache)
    {
    }

    public function onAuthAttempt(TwoFactorAuthenticationEvent $event): void
    {
        $key = '2fa-failures:'.$event->getToken()->getUserIdentifier();
        $count = (int) $this->redisCache->get($key);

        if ($count >= self::MAX_ATTEMPTS) {
            throw new CustomUserMessageAuthenticationException(sprintf('Too many authentication failures. Try again in %d minutes.', self::RATE_LIMIT_DURATION));
        }
    }

    public function onAuthFailure(TwoFactorAuthenticationEvent $event): void
    {
        $key = '2fa-failures:'.$event->getToken()->getUserIdentifier();

        $this->redisCache->multi();
        $this->redisCache->incr($key);
        $this->redisCache->expire($key, self::RATE_LIMIT_DURATION * 60);
        $this->redisCache->exec();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TwoFactorAuthenticationEvents::FAILURE => 'onAuthFailure',
            TwoFactorAuthenticationEvents::ATTEMPT => 'onAuthAttempt',
        ];
    }
}
