<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packagist\WebBundle\Security;

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
    const MAX_ATTEMPTS = 5;
    const RATE_LIMIT_DURATION = 15; // in minutes

    /** @var Client */
    protected $redis;

    public function __construct(Client $redis)
    {
        $this->redis = $redis;
    }

    public function onAuthAttempt(TwoFactorAuthenticationEvent $event)
    {
        $key = '2fa-failures:'.$event->getToken()->getUsername();
        $count = (int)$this->redis->get($key);

        if ($count >= self::MAX_ATTEMPTS) {
            throw new CustomUserMessageAuthenticationException(sprintf('Too many authentication failures. Try again in %d minutes.', self::RATE_LIMIT_DURATION));
        }
    }

    public function onAuthFailure(TwoFactorAuthenticationEvent $event)
    {
        $key = '2fa-failures:'.$event->getToken()->getUsername();

        $this->redis->multi();
        $this->redis->incr($key);
        $this->redis->expire($key, self::RATE_LIMIT_DURATION * 60);
        $this->redis->exec();
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            TwoFactorAuthenticationEvents::FAILURE => 'onAuthFailure',
            TwoFactorAuthenticationEvents::ATTEMPT => 'onAuthAttempt',
        ];
    }
}