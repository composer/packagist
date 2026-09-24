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

namespace App\Package;

use Predis\Client;

/**
 * Remembers how far each dump worker got, so the staleness select can ask what changed since then
 * instead of re-deriving it from the whole package table every two seconds.
 */
class DumpWatermark
{
    /**
     * How long a completed run is trusted for. When it lapses the caller falls back to the unbounded
     * select — which is what recovers a package stranded by a dump that failed after it was selected,
     * since nothing re-marks it, and what happens anyway when Redis loses the key.
     */
    public const int TTL_SECONDS = 300;

    /**
     * Taken off the select timestamp. dumpRequestedAt and crawledAt hold whole seconds, so a write
     * landing in the same second as the select would otherwise fall outside the next window.
     */
    private const SAFETY_MARGIN = '-5 seconds';

    public function __construct(private readonly Client $redis)
    {
    }

    /**
     * Null means there is no trustworthy watermark — first run, evicted key, or the TTL lapsed. It
     * must be read as "sweep everything", never as "nothing changed".
     */
    public function since(int $workerId, int $numWorkers): ?\DateTimeImmutable
    {
        $value = $this->redis->get($this->key($workerId, $numWorkers));

        return \is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : null;
    }

    /**
     * Stamped from before the select rather than after the dump, so anything marked while the run was
     * in flight is still picked up by the next one. Only call it once the dump succeeded: an aborted
     * run has to leave the previous watermark in place.
     */
    public function markCompleted(int $workerId, int $numWorkers, \DateTimeImmutable $selectedAt): void
    {
        $this->redis->setex(
            $this->key($workerId, $numWorkers),
            self::TTL_SECONDS,
            $selectedAt->modify(self::SAFETY_MARGIN)->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Keyed by the worker layout as well as the id: changing --num-workers reshards which packages a
     * worker owns, so an old watermark would vouch for ids it never dumped.
     */
    private function key(int $workerId, int $numWorkers): string
    {
        return 'metadata-dump:watermark:'.$numWorkers.':'.$workerId;
    }
}
