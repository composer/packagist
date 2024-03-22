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

use App\Entity\User;
use Predis\Client;
use Predis\Connection\ConnectionException;
use Predis\Profile\RedisProfile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class RecaptchaHelper
{
    public function __construct(
        private readonly Client $redisCache,
        private readonly bool $recaptchaEnabled,
        private readonly RequestStack $requestStack,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthenticationUtils $authenticationUtils,
    ){}

    public function buildContext(): RecaptchaContext
    {
        return new RecaptchaContext(
            $this->requestStack->getMainRequest()?->getClientIp() ?: '',
            $this->getCurrentUsername(),
            (bool) $this->requestStack->getMainRequest()?->request->has('g-recaptcha-response'),
        );
    }

    public function requiresRecaptcha(RecaptchaContext $context): bool
    {
        if (!$this->recaptchaEnabled) {
            return false;
        }

        $keys = $context->getRedisKeys();
        if (!$keys) {
            return false;
        }

        try {
            $result = $this->redisCache->mget($keys);
        } catch (ConnectionException) {
            return false;
        }
        foreach ($result as $count) {
            if ($count >= 3) {
                return true;
            }
        }

        return false;
    }

    public function increaseCounter(RecaptchaContext $context): void
    {
        if (!$this->recaptchaEnabled) {
            return;
        }

        /** @phpstan-ignore-next-line */
        $this->redisCache->incrFailedLoginCounter(...$context->getRedisKeys());
    }

    public function clearCounter(RecaptchaContext $context): void
    {
        if (!$this->recaptchaEnabled) {
            return;
        }

        $keys = $context->getRedisKeys(true);
        if (count($keys) > 0) {
            $this->redisCache->del($keys);
        }
    }

    private function getCurrentUsername(): string
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if ($user instanceof User) {
            return $user->getUsername();
        }

        return $this->authenticationUtils->getLastUsername();
    }
}
