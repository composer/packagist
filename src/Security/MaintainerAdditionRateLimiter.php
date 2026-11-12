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
use Predis\PredisException;

/**
 * Adding a maintainer emails them, so cap additions per IP and per added user to stop
 * the add/remove maintainer cycle being used to mail-bomb someone. Fails open on Redis outages.
 */
class MaintainerAdditionRateLimiter
{
    private const int PER_IP_PER_HOUR = 20;
    private const int PER_TARGET_PER_HOUR = 20;

    public function __construct(private readonly Client $redisCache)
    {
    }

    public function isLimited(User $maintainer, ?string $ip): bool
    {
        try {
            if ((int) $this->redisCache->get($this->targetKey($maintainer)) >= self::PER_TARGET_PER_HOUR) {
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

    public function recordAddition(User $maintainer, ?string $ip): void
    {
        try {
            $this->redisCache->multi();
            $this->redisCache->incr($this->targetKey($maintainer));
            $this->redisCache->expire($this->targetKey($maintainer), 3600);
            if ($ip !== null) {
                $this->redisCache->incr($this->ipKey($ip));
                $this->redisCache->expire($this->ipKey($ip), 3600);
            }
            $this->redisCache->exec();
        } catch (PredisException) {
        }
    }

    private function targetKey(User $maintainer): string
    {
        return 'maintainer-add:user:'.$maintainer->getId();
    }

    private function ipKey(string $ip): string
    {
        return 'maintainer-add:ip:'.$ip;
    }
}
