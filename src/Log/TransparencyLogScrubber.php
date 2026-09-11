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

namespace App\Log;

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
    private const SCRUB_AT_ANY_DEPTH = [
        'email',
        'email_from',
        'email_to',
        'internalReason',
        'internalReasonText',
        'internal_note',
    ];

    /**
     * Record types whose `metadata` attribute is a version metadata blob
     * ({@see \App\Entity\Version::toArray()}), and which are therefore reduced to the published
     * subset below instead of having the blob dropped.
     *
     * Any other type's `metadata` is dropped, so a new record type that grows one is held out of the
     * public log until someone decides what of it is publishable. A new type carrying a version blob
     * belongs in this list; a new type carrying a different blob needs its own reduction rather than
     * an entry here.
     */
    private const VERSION_METADATA_TYPES = [
        AuditLogEventType::VersionCreated,
    ];

    /**
     * Scalar keys published out of a version metadata blob.
     */
    private const PUBLISHED_METADATA_KEYS = [
        'version_normalized',
    ];

    /**
     * Sections of a version metadata blob, and the keys published out of each: what the version
     * resolved to at publication time.
     *
     * Without the reference a leaf says 1.2.3 was published but nothing about what it contained, so
     * a delete-and-recreate under the same version leaves no trace. It attests what the upstream host
     * told us at crawl time, not bytes we verified: `dist.shasum` is whatever the VCS driver reported
     * ({@see \App\Package\Updater}).
     */
    private const PUBLISHED_METADATA_SECTIONS = [
        'source' => ['type', 'url', 'reference'],
        'dist' => ['type', 'url', 'reference', 'shasum'],
    ];

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public function scrub(AuditLogEventType $type, array $attributes): array
    {
        $attributes = \in_array($type, self::VERSION_METADATA_TYPES, true)
            ? $this->reduceVersionMetadata($attributes)
            : $this->removeMetadata($attributes);

        return $this->scrubAtAnyDepth($attributes);
    }

    /**
     * Reduces the `metadata` blob to {@see self::PUBLISHED_METADATA_KEYS} and
     * {@see self::PUBLISHED_METADATA_SECTIONS}, dropping the key entirely when all keys get removed.
     *
     * An allow-list instead of a deny-list, because the metadata blob is from the publisher's
     * composer.json: we do not control its shape.
     *
     * Only non-empty strings are kept, so nothing nested can slip through a published section.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private function reduceVersionMetadata(array $attributes): array
    {
        $metadata = $attributes['metadata'] ?? null;
        if (!\is_array($metadata)) {
            return $this->removeMetadata($attributes);
        }

        $published = [];
        foreach (self::PUBLISHED_METADATA_KEYS as $key) {
            $value = $metadata[$key] ?? null;
            if (\is_string($value) && $value !== '') {
                $published[$key] = $value;
            }
        }

        foreach (self::PUBLISHED_METADATA_SECTIONS as $section => $keys) {
            $sectionValues = [];
            foreach ($keys as $key) {
                $value = \is_array($metadata[$section] ?? null) ? ($metadata[$section][$key] ?? null) : null;
                if (\is_string($value) && $value !== '') {
                    $sectionValues[$key] = $value;
                }
            }

            if ($sectionValues !== []) {
                $published[$section] = $sectionValues;
            }
        }

        if ($published === []) {
            return $this->removeMetadata($attributes);
        }

        $attributes['metadata'] = $published;

        return $attributes;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private function removeMetadata(array $attributes): array
    {
        unset($attributes['metadata']);

        return $attributes;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function scrubAtAnyDepth(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (\is_string($key) && \in_array($key, self::SCRUB_AT_ANY_DEPTH, true)) {
                continue;
            }

            $result[$key] = \is_array($item) ? $this->scrubAtAnyDepth($item) : $item;
        }

        return $result;
    }
}
