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

namespace App\Support;

use App\Entity\User;
use Predis\Client;
use Predis\PredisException;

/**
 * Per-user and per-IP caps on support submissions, shaped like TwoFactorAuthRateLimiter.
 *
 * The real dedupe is the support_request_open_uniq index, which caps a user at one open request per
 * type; this only stops someone churning open/closed cycles. Everything fails open on a Redis
 * outage, as RecaptchaHelper does: Redis being down must not lock a user out of account recovery.
 */
class SupportRequestRateLimiter
{
    private const int PER_USER_PER_DAY = 3;
    private const int PER_IP_PER_HOUR = 20;

    public function __construct(private readonly Client $redisCache)
    {
    }

    public function isLimited(User $user, SupportRequestType $type, ?string $ip): bool
    {
        try {
            // Keyed on the id, not the user identifier: the identifier can be either the username
            // or the email, which would split the counter for the same account.
            if ((int) $this->redisCache->get($this->userKey($user, $type)) >= self::PER_USER_PER_DAY) {
                return true;
            }

            if ($ip !== null && (int) $this->redisCache->get($this->ipKey($ip)) >= self::PER_IP_PER_HOUR) {
                return true;
            }
        } catch (PredisException) {
            return false;
        }

        return false;
    }

    public function recordSubmission(User $user, SupportRequestType $type, ?string $ip): void
    {
        try {
            $this->redisCache->multi();
            $this->redisCache->incr($this->userKey($user, $type));
            $this->redisCache->expire($this->userKey($user, $type), 86400);
            if ($ip !== null) {
                $this->redisCache->incr($this->ipKey($ip));
                $this->redisCache->expire($this->ipKey($ip), 3600);
            }
            $this->redisCache->exec();
        } catch (PredisException) {
        }
    }

    private function userKey(User $user, SupportRequestType $type): string
    {
        return 'support:'.$type->value.':user:'.$user->getId();
    }

    private function ipKey(string $ip): string
    {
        return 'support:any:ip:'.$ip;
    }
}
