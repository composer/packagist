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
 * Where the v2 dump's staleness select starts looking, per worker. It is anchored on the last pass
 * that dumped everything it selected, not on now, so a CDN outage longer than the window cannot age
 * stale packages out of it unseen.
 */
class V2DumpWindow
{
    private const string KEY_PREFIX = 'metadata-dump-v2:complete-pass:';

    /**
     * How far before the anchor each pass looks again: a mark that committed late, a clock a few
     * seconds out, or an id dropped by the command's backlog cap is found next time instead of stranded.
     */
    private const string WIDTH = '-1 hour';

    /** Caps the select's cost when a worker keeps failing for a long time, or the anchor is lost. */
    private const string MAX_LOOKBACK = '-1 day';

    public function __construct(
        private readonly Client $redis,
    ) {
    }

    public function since(int $workerId): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        $floor = $now->modify(self::MAX_LOOKBACK);
        $lastCompletePass = $this->redis->get(self::KEY_PREFIX.$workerId);
        if ($lastCompletePass === null) {
            return $floor;
        }

        return max($floor, $now->setTimestamp(min($now->getTimestamp(), (int) $lastCompletePass))->modify(self::WIDTH));
    }

    /**
     * @param \DateTimeImmutable $selectedAt when the pass's select ran: what became stale after that was not in it
     */
    public function recordCompletePass(int $workerId, \DateTimeImmutable $selectedAt): void
    {
        $this->redis->set(self::KEY_PREFIX.$workerId, (string) $selectedAt->getTimestamp());
    }
}
