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
 * Where the v2 dump's staleness select starts looking, per worker. It is anchored on the oldest request
 * a pass left pending (failed, aborted or cut by the backlog cap), so nothing can age out of the window
 * while it still waits to be dumped.
 */
class V2DumpWindow
{
    private const string KEY_PREFIX = 'metadata-dump-v2:oldest-pending:';

    /** Margin before the anchor: a mark that committed late or a clock a few seconds out is still found. */
    private const int MARGIN = 600;

    /** Caps the select's cost when a package keeps failing for a long time, or the anchor is lost. */
    private const int MAX_LOOKBACK = 86400;

    public function __construct(
        private readonly Client $redis,
    ) {
    }

    public function since(int $workerId): \DateTimeImmutable
    {
        $now = time();
        $anchor = $this->redis->get(self::KEY_PREFIX.$workerId);
        $anchor = $anchor === null ? 0 : min($now, (int) $anchor);

        return new \DateTimeImmutable('@'.max($now - self::MAX_LOOKBACK, $anchor - self::MARGIN))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    public function record(int $workerId, \DateTimeImmutable $anchor): void
    {
        $this->redis->set(self::KEY_PREFIX.$workerId, (string) $anchor->getTimestamp());
    }

    /**
     * @param array<int, string|null> $pending    id => staleSince of what the pass selected but did not dump
     * @param \DateTimeImmutable      $selectedAt when the pass's select ran: what became stale after that was not in it
     */
    public static function anchorFor(array $pending, \DateTimeImmutable $selectedAt): \DateTimeImmutable
    {
        $anchor = $selectedAt;
        foreach ($pending as $staleSince) {
            if ($staleSince !== null) {
                $anchor = min($anchor, new \DateTimeImmutable($staleSince));
            }
        }

        return $anchor;
    }
}
