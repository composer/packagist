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

use Symfony\Component\HttpFoundation\Request;

class RecaptchaContext
{
    private const LOGIN_BASE_KEY_IP = 'bf:login:ip:';
    private const LOGIN_BASE_KEY_USER = 'bf:login:user:';

    public function __construct(
        public readonly string $ip,
        public readonly string $username,
        public readonly ?string $recaptcha,
    ) {}

    /**
     * @return string[]
     */
    public function getRedisKeys(bool $forClear = false): array
    {
        return array_filter([
            ! $forClear && $this->ip ? self::LOGIN_BASE_KEY_IP . $this->ip : null,
            $this->username ? self::LOGIN_BASE_KEY_USER . strtolower($this->username) : null,
        ]);
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            $request->getClientIp() ?: '',
            (string) $request->request->get('_username'),
            (string) $request->request->get('g-recaptcha-response'),
        );
    }
}
