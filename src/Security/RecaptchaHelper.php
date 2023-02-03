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
use Predis\Profile\RedisProfile;
use Symfony\Component\HttpFoundation\Request;

class RecaptchaHelper
{
    private const LOGIN_BASE_KEY_IP = 'bf:login:ip:';
    private const LOGIN_BASE_KEY_USER = 'bf:login:user:';

    private Client $redisCache;
    private bool $recaptchaEnabled;

    public function __construct(Client $redisCache, bool $recaptchaEnabled)
    {
        $this->redisCache = $redisCache;
        $this->recaptchaEnabled = $recaptchaEnabled;
    }

    public function requiresRecaptcha(string $ip, string $username): bool
    {
        if (!$this->recaptchaEnabled) {
            return false;
        }

        $keys = [self::LOGIN_BASE_KEY_IP . $ip];
        if ($username) {
            $keys[] = self::LOGIN_BASE_KEY_USER . strtolower($username);
        }

        $result = $this->redisCache->mget($keys);
        foreach ($result as $count) {
            if ($count >= 3) {
                return true;
            }
        }

        return false;
    }

    public function increaseCounter(Request $request): void
    {
        if (!$this->recaptchaEnabled) {
            return;
        }

        $ipKey = self::LOGIN_BASE_KEY_IP . $request->getClientIp();
        $userKey = $this->getUserKey($request);
        /** @phpstan-ignore-next-line */
        $this->redisCache->incrFailedLoginCounter($ipKey, $userKey);
    }

    public function clearCounter(Request $request): void
    {
        if (!$this->recaptchaEnabled) {
            return;
        }

        $userKey = $this->getUserKey($request);
        $this->redisCache->del([$userKey]);
    }

    private function getUserKey(Request $request): string
    {
        $username = (string) $request->request->get('_username');

        return self::LOGIN_BASE_KEY_USER . strtolower($username);
    }
}
