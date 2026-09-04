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

namespace App\Util;

use Predis\Client;

/**
 * Collects keys with SCAN rather than KEYS.
 */
class RedisScanner
{
    /**
     * Returns every key matching $pattern, as KEYS would, without blocking Redis.
     *
     * KEYS walks the whole keyspace to completion on the single main thread, so nothing else is
     * served for its duration. SCAN does the same work in bounded increments.
     *
     * Two caveats come with that. SCAN can return the same key more than once, so results are
     * de-duplicated here. And it only guarantees keys present for the whole iteration: one created
     * midway may be missed, which is fine for the nightly migrations since anything missed is
     * simply picked up on the next run.
     *
     * @return list<string>
     */
    public static function keys(Client $redis, string $pattern, int $count = 1000): array
    {
        $keys = [];
        $cursor = '0';

        do {
            /** @var array{0: int|string, 1: list<string>} $result */
            $result = $redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => $count]);
            [$cursor, $batch] = $result;

            foreach ($batch as $key) {
                $keys[$key] = true;
            }
        } while ((string) $cursor !== '0');

        return array_keys($keys);
    }
}
