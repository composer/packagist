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

namespace App\Audit;

/**
 * Strips private data out of an audit record's attributes before they are copied/fanned out into the public
 * package transparency log.
 *
 * The transparency log is intended to be immutable and eventually published, so PII/admin-only data
 * is removed at write (projection) time rather than merely masked at display time
 */
class TransparencyLogScrubber
{
    /**
     * Keys removed anywhere in the attribute tree: email addresses and admin-only moderation notes.
     */
    private const DENYLIST = [
        'email',
        'email_from',
        'email_to',
        'internalReason',
        'internalReasonText',
        'internal_note',
    ];

    /**
     * Top-level keys dropped because they are bulky and already public elsewhere (the full version
     * metadata blob is available on the package version page).
     */
    private const DROP_TOP_LEVEL = [
        'metadata',
    ];

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public function scrub(array $attributes): array
    {
        foreach (self::DROP_TOP_LEVEL as $key) {
            unset($attributes[$key]);
        }

        return $this->stripDenylisted($attributes);
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function stripDenylisted(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (\is_string($key) && \in_array($key, self::DENYLIST, true)) {
                continue;
            }

            $result[$key] = \is_array($item) ? $this->stripDenylisted($item) : $item;
        }

        return $result;
    }
}
